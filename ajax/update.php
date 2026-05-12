<?php
/**
 * AJAX endpoint: applies a single field change to one or more tickets.
 *
 * POST params:
 *   ticket_ids[] : array of ticket IDs
 *   field        : one of the keys in $allowed_fields below
 *   value        : new value (semantics depend on field — see below)
 *
 * Field handling falls into six shapes:
 *   - Simple Ticket column  (status, priority, urgency, impact,
 *     itilcategories_id) → $ticket->update([$field => (int) $value])
 *   - Actor field           (_itil_assign, _itil_requester, _itil_observer)
 *     → replace the existing actor of that type on Ticket_User
 *   - Pending reason        (pendingreasons_id)
 *     → upsert PendingReason_Item AND set status to Pending (4)
 *   - Text                  (name, content)
 *     → trimmed string write to the column. `name` is capped at 255 chars
 *       to match the column; `content` (description) has no realistic cap
 *       since the column is LONGTEXT.
 *   - Date/datetime         (time_to_resolve)
 *     → 'YYYY-MM-DDTHH:MM' (datetime-local) → 'Y-m-d H:i:s'.
 *       Empty string clears the column to NULL.
 *   - Numeric               (cost_fixed)
 *     → cast to float, must be >= 0
 *   - Ticket links          (parent_tickets, child_tickets)
 *     → for each selected ticket T, add one Ticket_Ticket row pointing
 *       at the picked ticket. Self-links and duplicates are rejected.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json');

if (!Session::haveRight('ticket', UPDATE)) {
    http_response_code(403);
    echo json_encode(['error' => 'No update permission']);
    exit;
}

$ticket_ids = $_POST['ticket_ids'] ?? [];
$field      = $_POST['field']      ?? '';
$value      = $_POST['value']      ?? '';

// Accept both array form (ticket_ids[]=1&ticket_ids[]=2) and any single
// scalar that may have slipped through. Also handle a comma-separated
// string just in case.
if (!is_array($ticket_ids)) {
    if (is_string($ticket_ids) && strpos($ticket_ids, ',') !== false) {
        $ticket_ids = array_map('trim', explode(',', $ticket_ids));
    } else {
        $ticket_ids = [$ticket_ids];
    }
}
$ticket_ids = array_values(array_filter($ticket_ids, function ($v) {
    return $v !== '' && $v !== null;
}));

if (empty($ticket_ids)) {
    http_response_code(400);
    echo json_encode(['error' => 'No tickets specified']);
    exit;
}

// Whitelist of fields the toolbar is allowed to edit. Mirrors the keys
// in PluginQuickassignConfig::getCatalog(); kept independent here so a
// missing/broken config class doesn't open up writes that shouldn't be.
$allowed_fields = [
    // Dropdown / actor / pending-reason
    '_itil_assign',
    '_itil_requester',
    '_itil_observer',
    'status',
    'priority',
    'urgency',
    'impact',
    'itilcategories_id',
    'pendingreasons_id',
    // Text / date / numeric
    'name',
    'content',
    'time_to_resolve',
    'cost_fixed',
    // Multi-select (ticket links)
    'parent_tickets',
    'child_tickets',
];
if (!in_array($field, $allowed_fields, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid field']);
    exit;
}

// ----- Per-shape value validation ----------------------------------------
// Different fields have different value shapes. We coerce + validate up
// front so the per-ticket loop below can assume a clean $value.

// Set of fields whose value is an integer (FK or enum code).
$int_fields = [
    '_itil_assign', '_itil_requester', '_itil_observer',
    'status', 'priority', 'urgency', 'impact',
    'itilcategories_id', 'pendingreasons_id',
    'parent_tickets', 'child_tickets',
];

if (in_array($field, $int_fields, true)) {
    $value = (int) $value;

    if ($field === '_itil_assign'
        || $field === '_itil_requester'
        || $field === '_itil_observer') {
        if ($value <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid user']);
            exit;
        }
    }
    if ($field === 'pendingreasons_id' && $value <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid pending reason']);
        exit;
    }
    if (($field === 'parent_tickets' || $field === 'child_tickets') && $value <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid ticket id']);
        exit;
    }

} elseif ($field === 'name') {
    // Title: trimmed, non-empty, max 255 chars (matches the column).
    $value = trim((string) $value);
    if ($value === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Title cannot be empty']);
        exit;
    }
    if (function_exists('mb_strlen') ? mb_strlen($value) > 255 : strlen($value) > 255) {
        http_response_code(400);
        echo json_encode(['error' => 'Title too long (max 255)']);
        exit;
    }

} elseif ($field === 'content') {
    // Description: trimmed, non-empty. The column is LONGTEXT (~4 GB),
    // so no realistic length cap. We mirror Title's "non-empty required"
    // behavior — clearing a ticket's description outright is rarely
    // intentional, and the user can post a single space if they really
    // want a blank-looking value.
    $value = trim((string) $value);
    if ($value === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Description cannot be empty']);
        exit;
    }

} elseif ($field === 'time_to_resolve') {
    // datetime-local sends 'YYYY-MM-DDTHH:MM' (no seconds). Empty = clear.
    $raw = trim((string) $value);
    if ($raw === '') {
        $value = null; // null → write NULL column
    } else {
        $ts = strtotime($raw);
        if ($ts === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date']);
            exit;
        }
        $value = date('Y-m-d H:i:s', $ts);
    }

} elseif ($field === 'cost_fixed') {
    // Allow comma decimal separator for European locales — convert to dot
    // before float parsing. Reject negatives.
    $raw = trim((string) $value);
    if ($raw === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Cost cannot be empty']);
        exit;
    }
    $raw = str_replace(',', '.', $raw);
    if (!is_numeric($raw)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid cost']);
        exit;
    }
    $value = (float) $raw;
    if ($value < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Cost must be >= 0']);
        exit;
    }
}

// Map our actor field keys to GLPI's CommonITILActor type constants.
// Defined here once so the loop body can do a single lookup.
$actor_type_map = [
    '_itil_assign'    => CommonITILActor::ASSIGN,
    '_itil_requester' => CommonITILActor::REQUESTER,
    '_itil_observer'  => CommonITILActor::OBSERVER,
];

$ticket  = new Ticket();
$results = ['success' => [], 'failed' => [], 'errors' => []];

foreach ($ticket_ids as $raw_id) {
    $id = (int) $raw_id;
    if ($id <= 0) {
        continue;
    }

    if (!$ticket->getFromDB($id)) {
        $results['failed'][]       = $id;
        $results['errors'][$id]    = 'not found';
        continue;
    }

    if (!$ticket->canUpdateItem()) {
        $results['failed'][]       = $id;
        $results['errors'][$id]    = 'no permission';
        continue;
    }

    $ok = false;

    if (isset($actor_type_map[$field])) {
        // ---------------------------------------------------------------
        // ACTOR FIELDS (assignee / requester / observer): we REPLACE any
        // existing actor of the same type, mirroring the v1.1.x behavior
        // that originally only handled Assignee. Same Zammad-style "one
        // owner / one requester" model — flip to add-without-replace by
        // commenting out the deletion loop.
        // ---------------------------------------------------------------
        $actor_type = $actor_type_map[$field];
        $tu         = new Ticket_User();
        $existing   = $tu->find([
            'tickets_id' => $id,
            'type'       => $actor_type,
        ]);
        foreach ($existing as $row) {
            $tu->delete(['id' => $row['id']], true);
        }

        $ok = (bool) $tu->add([
            'tickets_id'       => $id,
            'users_id'         => $value,
            'type'             => $actor_type,
            'use_notification' => 1,
        ]);

        // Touch the ticket so date_mod / notifications fire
        if ($ok) {
            $ticket->update(['id' => $id, 'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')]);
        }

    } elseif ($field === 'pendingreasons_id') {
        // ---------------------------------------------------------------
        // PENDING REASON: GLPI stores the pending reason in a separate
        // relation table (glpi_pendingreasons_items), and the ticket
        // status doesn't change automatically. We replace any existing
        // pending-reason link and force status to Pending so the field
        // actually has user-visible effect.
        // ---------------------------------------------------------------
        if (!class_exists('PendingReason_Item')) {
            $results['failed'][]    = $id;
            $results['errors'][$id] = 'pending-reason API unavailable';
            continue;
        }

        $pri      = new PendingReason_Item();
        $existing = $pri->find(['itemtype' => 'Ticket', 'items_id' => $id]);
        foreach ($existing as $row) {
            $pri->delete(['id' => $row['id']], true);
        }

        $added = (bool) $pri->add([
            'itemtype'          => 'Ticket',
            'items_id'          => $id,
            'pendingreasons_id' => $value,
        ]);

        if ($added) {
            $ok = (bool) $ticket->update([
                'id'     => $id,
                'status' => CommonITILObject::WAITING, // 4 = Pending
            ]);
        }

    } elseif ($field === 'parent_tickets' || $field === 'child_tickets') {
        // ---------------------------------------------------------------
        // TICKET LINKS: each pick (one $value, one ticket id) creates ONE
        // Ticket_Ticket row per currently-selected ticket. The semantics:
        //   parent_tickets, value=P → make P a parent of T (T is SON_OF P)
        //   child_tickets,  value=C → make C a child  of T (T is PARENT_OF C)
        // We use Ticket_Ticket::add() so GLPI's prepareInputForAdd handles
        // the symmetric-link normalization. Self-links and existing dupes
        // are rejected.
        // ---------------------------------------------------------------
        if ($value === $id) {
            $results['failed'][]    = $id;
            $results['errors'][$id] = 'cannot link a ticket to itself';
            continue;
        }
        if (!class_exists('Ticket_Ticket')) {
            $results['failed'][]    = $id;
            $results['errors'][$id] = 'Ticket_Ticket API unavailable';
            continue;
        }

        $link_const = ($field === 'parent_tickets')
            ? Ticket_Ticket::SON_OF      // T is son of value → value is parent of T
            : Ticket_Ticket::PARENT_OF;  // T is parent of value → value is child of T

        // Pre-flight duplicate check: GLPI considers a link to exist in
        // either direction (because SON_OF and PARENT_OF are inverses
        // that get normalized). Look for both possibilities.
        $tt = new Ticket_Ticket();
        $existing = $tt->find([
            'OR' => [
                [
                    'tickets_id_1' => $id,
                    'tickets_id_2' => $value,
                ],
                [
                    'tickets_id_1' => $value,
                    'tickets_id_2' => $id,
                ],
            ],
        ]);
        if (!empty($existing)) {
            $results['failed'][]    = $id;
            $results['errors'][$id] = 'link already exists';
            continue;
        }

        $ok = (bool) $tt->add([
            'tickets_id_1' => $id,
            'tickets_id_2' => $value,
            'link'         => $link_const,
        ]);

    } elseif ($field === 'name') {
        // ---------------------------------------------------------------
        // TITLE: pre-validated to non-empty trimmed string above.
        // ---------------------------------------------------------------
        $ok = (bool) $ticket->update([
            'id'   => $id,
            'name' => $value,
        ]);

    } elseif ($field === 'content') {
        // ---------------------------------------------------------------
        // DESCRIPTION: pre-validated to non-empty trimmed string above.
        //
        // The textarea delivers PLAIN TEXT with literal \n newlines, but
        // GLPI renders this column as HTML. Save the value as plain
        // text and the line breaks vanish in the ticket form view.
        // Convert newlines to <br>\n and HTML-escape the rest so we
        // never inject unintended markup. (Users wanting real rich text
        // — bold, lists, links — still use the full ticket form's
        // TinyMCE editor; this path is just a fast-edit shortcut.)
        // ---------------------------------------------------------------
        $safe = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = nl2br($safe, false); // false = HTML4-style <br>, matches GLPI conventions

        $ok = (bool) $ticket->update([
            'id'      => $id,
            'content' => $html,
        ]);

    } elseif ($field === 'time_to_resolve') {
        // ---------------------------------------------------------------
        // SLA TARGET DATE: $value is either a 'Y-m-d H:i:s' string or
        // null (cleared). Pass through to update().
        // ---------------------------------------------------------------
        $ok = (bool) $ticket->update([
            'id'              => $id,
            'time_to_resolve' => $value, // string or null
        ]);

    } elseif ($field === 'cost_fixed') {
        // ---------------------------------------------------------------
        // FIXED COST: $value is a validated float >= 0. GLPI's column is
        // decimal(20,4) — float precision is fine.
        // ---------------------------------------------------------------
        $ok = (bool) $ticket->update([
            'id'         => $id,
            'cost_fixed' => $value,
        ]);

    } else {
        // ---------------------------------------------------------------
        // SIMPLE COLUMN UPDATE: status / priority / urgency / impact /
        // itilcategories_id all live on the ticket row itself.
        // ---------------------------------------------------------------
        $ok = (bool) $ticket->update([
            'id'   => $id,
            $field => $value,
        ]);
    }

    if ($ok) {
        $results['success'][] = $id;
    } else {
        $results['failed'][]    = $id;
        $results['errors'][$id] = 'update failed';
    }
}

echo json_encode($results);
