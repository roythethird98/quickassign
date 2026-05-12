<?php
/**
 * AJAX endpoint: returns one ticket's `content` column for inline-edit
 * pre-fill.
 *
 * GET (or POST) params:
 *   ticket_id : the ticket whose description to fetch
 *
 * Response shape:
 *   { "id": 42, "content_html": "<p>...</p>", "content_text": "..." }
 *
 * - content_html is the raw column value (HTML, as GLPI stores it).
 * - content_text is the HTML stripped down to plain text with newlines
 *   for block boundaries — what the inline-edit textarea actually
 *   pre-fills with. Doing the HTML→text conversion server-side keeps
 *   the JS simple and avoids subtle encoding gotchas on the client.
 *
 * Permission gate: requires the same `ticket / READ` right that GLPI
 * uses for opening the ticket. The endpoint never returns content for
 * tickets the user can't see otherwise.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json');

if (!Session::haveRight('ticket', READ)) {
    http_response_code(403);
    echo json_encode(['error' => 'No read permission']);
    exit;
}

$ticket_id = (int) ($_REQUEST['ticket_id'] ?? 0);
if ($ticket_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid ticket id']);
    exit;
}

try {
    $ticket = new Ticket();
    if (!$ticket->getFromDB($ticket_id)) {
        http_response_code(404);
        echo json_encode(['error' => 'Ticket not found']);
        exit;
    }
    // Per-ticket permission. canViewItem() honors group / requester /
    // assignee scoping in addition to the profile right.
    if (!$ticket->canViewItem()) {
        http_response_code(403);
        echo json_encode(['error' => 'Cannot view this ticket']);
        exit;
    }

    $html = (string) ($ticket->fields['content'] ?? '');
    $text = html_to_plain_text_for_prefill($html);

    echo json_encode([
        'id'           => $ticket_id,
        'content_html' => $html,
        'content_text' => $text,
    ]);

} catch (\Throwable $e) {
    error_log('[quickassign ticket_content.php] ' . $e->getMessage()
              . ' at ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load ticket content']);
}

/**
 * Convert GLPI-stored description HTML to a plain-text representation
 * suitable for editing in a <textarea>.
 *
 * Goals:
 *   - <br>, </p>, </div>, </li>, </tr> → newline boundaries
 *   - <li>...</li> → "- ..." so bullet lists at least read as lists
 *   - Strip everything else
 *   - Decode entities (&amp; → &, &nbsp; → space, ...)
 *   - Collapse 3+ consecutive newlines to 2 (preserve paragraph breaks
 *     but don't keep huge gaps)
 */
function html_to_plain_text_for_prefill(string $html): string
{
    if ($html === '') {
        return '';
    }

    // Block-level closes → newlines. Order matters: handle </li> before
    // we strip surrounding <ul>/<ol>, and prefix list items with "- ".
    $s = $html;
    $s = preg_replace('#<br\s*/?>#i', "\n", $s);
    $s = preg_replace('#<li[^>]*>#i', "- ", $s);
    $s = preg_replace('#</li\s*>#i', "\n", $s);
    $s = preg_replace('#</p\s*>#i', "\n\n", $s);
    $s = preg_replace('#</div\s*>#i', "\n", $s);
    $s = preg_replace('#</tr\s*>#i', "\n", $s);
    $s = preg_replace('#</h[1-6]\s*>#i', "\n\n", $s);

    // Now strip remaining tags wholesale.
    $s = strip_tags($s);

    // Decode entities (named + numeric).
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Normalize whitespace: trim per-line, collapse 3+ newlines to 2.
    $lines = preg_split("/\r\n|\n|\r/", $s);
    $lines = array_map('rtrim', $lines);
    $s     = implode("\n", $lines);
    $s     = preg_replace("/\n{3,}/", "\n\n", $s);

    return trim($s);
}
