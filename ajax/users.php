<?php
/**
 * Returns users in Select2 format. The exact filter depends on the
 * caller's actor type, supplied via ?actor=:
 *
 *   assign    (default)  Users with the Ticket::OWN right — i.e. assignable
 *                        technicians. Same query the v1.1.x toolbar used.
 *   requester            Any active, non-deleted user in the active entity.
 *   observer             Same filter as requester. Observers are typically
 *                        any user who needs visibility on a ticket.
 *
 * Lives under /plugins/quickassign/ajax/, which GLPI's CSRF middleware
 * exempts from automatic token validation.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json');

if (!Session::haveRight('ticket', UPDATE)) {
    http_response_code(403);
    echo json_encode(['results' => []]);
    exit;
}

try {
    global $DB;

    $search     = trim((string) ($_POST['searchText'] ?? $_GET['searchText'] ?? ''));
    $actor      = trim((string) ($_POST['actor']      ?? $_GET['actor']      ?? 'assign'));
    $page       = max(1, (int) ($_POST['page'] ?? $_GET['page'] ?? 1));
    $page_limit = 30;
    $start      = ($page - 1) * $page_limit;

    // Whitelist actors. Anything unexpected falls back to 'assign' — the
    // strictest filter — rather than silently returning the universal set.
    if (!in_array($actor, ['assign', 'requester', 'observer'], true)) {
        $actor = 'assign';
    }

    // Active entity scope from the session
    $entities = $_SESSION['glpiactiveentities'] ?? [0];
    if (!is_array($entities) || empty($entities)) {
        $entities = [0];
    }
    $entity_list = implode(',', array_map('intval', $entities));

    // Search clause
    $search_clause = '';
    if ($search !== '') {
        $escaped = method_exists($DB, 'escape')
            ? $DB->escape($search)
            : addslashes($search);
        $like = "'%{$escaped}%'";
        $search_clause = " AND (u.name LIKE {$like}"
                       . " OR u.realname LIKE {$like}"
                       . " OR u.firstname LIKE {$like})";
    }

    $limit = $page_limit + 1; // +1 to detect "more"

    if ($actor === 'assign') {
        // Ticket::OWN (= 32768) is the "Be in charge of a ticket" right —
        // the same right GLPI uses to decide who can be an assignee.
        $own_right = (int) Ticket::OWN;
        $sql = "SELECT DISTINCT u.id, u.name, u.realname, u.firstname
                FROM glpi_users u
                INNER JOIN glpi_profiles_users pu ON pu.users_id = u.id
                INNER JOIN glpi_profilerights pr ON pr.profiles_id = pu.profiles_id
                WHERE u.is_active = 1
                  AND u.is_deleted = 0
                  AND pr.name = 'ticket'
                  AND (pr.rights & {$own_right}) > 0
                  AND pu.entities_id IN ({$entity_list})
                  {$search_clause}
                ORDER BY u.realname, u.firstname, u.name
                LIMIT {$start}, {$limit}";
    } else {
        // Requester / Observer: any active user with profile in the
        // active entity. We don't filter on a specific right because end
        // users (who naturally lack ticket-management rights) are valid
        // requesters and observers.
        $sql = "SELECT DISTINCT u.id, u.name, u.realname, u.firstname
                FROM glpi_users u
                INNER JOIN glpi_profiles_users pu ON pu.users_id = u.id
                WHERE u.is_active = 1
                  AND u.is_deleted = 0
                  AND pu.entities_id IN ({$entity_list})
                  {$search_clause}
                ORDER BY u.realname, u.firstname, u.name
                LIMIT {$start}, {$limit}";
    }

    // GLPI 11 prefers doQuery(), older versions only have query()
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

        $first = trim((string) ($row['firstname'] ?? ''));
        $last  = trim((string) ($row['realname']  ?? ''));
        $login = (string) $row['name'];

        $full    = trim($first . ' ' . $last);
        $display = ($full === '') ? $login : "{$full} ({$login})";

        $results[] = [
            'id'   => (int) $row['id'],
            'text' => $display,
        ];
    }

    echo json_encode([
        'results'    => $results,
        'pagination' => ['more' => $row_count > $page_limit],
    ]);

} catch (\Throwable $e) {
    error_log('[quickassign users.php] ' . $e->getMessage()
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
