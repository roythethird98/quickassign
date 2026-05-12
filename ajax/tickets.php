<?php
/**
 * Returns tickets in Select2 format. Used by the Parent Tickets and
 * Child Tickets multi-selects in the toolbar.
 *
 * POST/GET params:
 *   searchText : free-text query — matched against ticket id (numeric) and
 *                ticket name (LIKE %term%).
 *   page       : 1-based page index (Select2 handles infinite-scroll).
 *   exclude    : comma-joined list of ticket IDs to omit from results
 *                (the currently selected tickets — prevents self-links).
 *
 * Scoped to the user's active entities and to tickets the user can read.
 * We don't filter on UPDATE rights here because the user is *picking* a
 * ticket to link (not editing it); the actual link write in update.php
 * will fail their per-ticket canUpdateItem() check if they shouldn't be
 * touching it.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json');

// Need to be able to update tickets to use the toolbar at all — same gate
// as the other AJAX endpoints in this plugin.
if (!Session::haveRight('ticket', UPDATE)) {
    http_response_code(403);
    echo json_encode(['results' => []]);
    exit;
}

try {
    global $DB;

    $search     = trim((string) ($_POST['searchText'] ?? $_GET['searchText'] ?? ''));
    $page       = max(1, (int) ($_POST['page'] ?? $_GET['page'] ?? 1));
    $page_limit = 30;
    $start      = ($page - 1) * $page_limit;

    // Excluded IDs (prevents listing the currently-selected tickets so a
    // user can't link a ticket to itself). Accepts array or csv string.
    $exclude_raw = $_POST['exclude'] ?? $_GET['exclude'] ?? [];
    if (!is_array($exclude_raw)) {
        $exclude_raw = $exclude_raw === '' ? [] : explode(',', (string) $exclude_raw);
    }
    $exclude = [];
    foreach ($exclude_raw as $v) {
        $n = (int) $v;
        if ($n > 0) $exclude[] = $n;
    }
    $exclude_clause = '';
    if (!empty($exclude)) {
        $exclude_clause = ' AND t.id NOT IN (' . implode(',', $exclude) . ')';
    }

    // Active entity scope. Tickets are entity-scoped so we limit to those
    // visible in the user's current session.
    $entities = $_SESSION['glpiactiveentities'] ?? [0];
    if (!is_array($entities) || empty($entities)) {
        $entities = [0];
    }
    $entity_list = implode(',', array_map('intval', $entities));

    // Search clause: id-as-int OR partial match on name. If the search
    // term is purely numeric we *also* keep the LIKE clause — a user
    // searching "42" might mean ticket #42 OR a ticket with "42" in the
    // title.
    $search_clause = '';
    if ($search !== '') {
        $escaped = method_exists($DB, 'escape')
            ? $DB->escape($search)
            : addslashes($search);
        $like = "'%{$escaped}%'";
        $id_clause = '';
        if (ctype_digit($search)) {
            $id_clause = " OR t.id = " . (int) $search;
        }
        $search_clause = " AND (t.name LIKE {$like}{$id_clause})";
    }

    $limit = $page_limit + 1; // +1 to detect "more"

    $sql = "SELECT t.id, t.name
            FROM glpi_tickets t
            WHERE t.is_deleted = 0
              AND t.entities_id IN ({$entity_list})
              {$exclude_clause}
              {$search_clause}
            ORDER BY t.id DESC
            LIMIT {$start}, {$limit}";

    $query_fn = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';
    $result   = $DB->{$query_fn}($sql);
    if (!$result) {
        throw new \RuntimeException('Query failed');
    }

    $results   = [];
    $row_count = 0;
    while ($row = $DB->fetchAssoc($result)) {
        $row_count++;
        if (count($results) >= $page_limit) {
            continue; // keep counting to know if there's more
        }

        $id    = (int) $row['id'];
        $name  = trim((string) ($row['name'] ?? ''));
        $label = '#' . $id . ($name !== '' ? ' — ' . $name : '');

        $results[] = [
            'id'   => $id,
            'text' => $label,
        ];
    }

    echo json_encode([
        'results'    => $results,
        'pagination' => ['more' => $row_count > $page_limit],
    ]);

} catch (\Throwable $e) {
    error_log('[quickassign tickets.php] ' . $e->getMessage()
              . ' at ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'results' => [],
        'debug'   => [
            'message' => $e->getMessage(),
            'file'    => basename($e->getFile()),
            'line'    => $e->getLine(),
        ],
    ]);
}
