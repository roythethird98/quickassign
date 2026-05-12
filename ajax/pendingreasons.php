<?php
/**
 * Returns pending reasons in Select2 format.
 *
 * Pending reasons are entity-aware (and may be recursive), but unlike
 * ITIL categories there's no helpdesk-visible flag — every entry in
 * glpi_pendingreasons is a candidate.
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
    $page       = max(1, (int) ($_POST['page'] ?? $_GET['page'] ?? 1));
    $page_limit = 30;
    $start      = ($page - 1) * $page_limit;

    $entities = $_SESSION['glpiactiveentities'] ?? [0];
    if (!is_array($entities) || empty($entities)) {
        $entities = [0];
    }
    $entity_list = implode(',', array_map('intval', $entities));

    $search_clause = '';
    if ($search !== '') {
        $escaped = method_exists($DB, 'escape')
            ? $DB->escape($search)
            : addslashes($search);
        $like = "'%{$escaped}%'";
        $search_clause = " AND (name LIKE {$like})";
    }

    $limit = $page_limit + 1;

    $sql = "SELECT id, name
            FROM glpi_pendingreasons
            WHERE (entities_id IN ({$entity_list})
                   OR (is_recursive = 1 AND entities_id = 0))
              {$search_clause}
            ORDER BY name
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
            continue;
        }
        $results[] = [
            'id'   => (int) $row['id'],
            'text' => (string) $row['name'],
        ];
    }

    echo json_encode([
        'results'    => $results,
        'pagination' => ['more' => $row_count > $page_limit],
    ]);

} catch (\Throwable $e) {
    error_log('[quickassign pendingreasons.php] ' . $e->getMessage()
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
