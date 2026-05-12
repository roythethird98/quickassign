# Quick Ticket Assignment - GLPI Plugin

A Zammad-style quick-edit plugin for the GLPI **Assistance > Tickets** list,
with two complementary surfaces:

- **Toolbar** - select one or more tickets with the row checkboxes and a
  floating bar appears at the bottom of the screen with one or more
  configurable widgets. Picking a value applies it to every selected ticket.
- **Inline edit** - click an editable cell directly in a ticket row and the
  cell turns into the same widget the toolbar would have used (text input,
  date picker, dropdown, etc.). The change applies to that one ticket.
  Immutable cells (Ticket ID, Opening Date, Last Update, …) keep their
  default behavior of opening the ticket.

Tested against **GLPI 11.0.x**.

---

## Installation

1. Copy the `quickassign` folder into your GLPI plugins directory:
   ```
   /var/www/html/glpi/plugins/quickassign/
   ```
   The folder name **must** be `quickassign` (it has to match the function
   suffixes in `setup.php`).

2. Set ownership/permissions so your web server can read the files. On a
   typical Apache/Debian setup:
   ```bash
   chown -R www-data:www-data /var/www/html/glpi/plugins/quickassign
   find /var/www/html/glpi/plugins/quickassign -type d -exec chmod 755 {} \;
   find /var/www/html/glpi/plugins/quickassign -type f -exec chmod 644 {} \;
   ```

3. In GLPI, go to **Setup > Plugins**. Click **Install** next to
   "Quick Ticket Assignment", then **Enable**. The install step creates the
   plugin's tables (`glpi_plugin_quickassign_fields` and
   `glpi_plugin_quickassign_settings`) and seeds the field table with the
   default four-field toolbar.

4. Navigate to **Assistance > Tickets**, select one or more tickets, and the
   toolbar will appear at the bottom of the page.

---

## Configuring the toolbar

Two routes lead to the same config page (admins only — requires the
`config / UPDATE` right):

- **Setup > Plugins > Installed**, then click the wrench (Configure) icon
  next to "Quick Ticket Assignment".
- **Setup > Quick Ticket Assignment** in the sidebar.

The page shows every available field with a checkbox to enable/disable it
and up/down arrows to reorder. At least one field must be enabled; the
upper bound is just the catalog size, so you can enable all available
fields if you want. Save commits the change; the toolbar picks it up on
the next page load. A "Reset to defaults" button restores the original
four fields **and** the default settings.

Below the field list, a **Settings** section holds plugin-wide toggles,
grouped into two subsections:

**Floating toolbar**

- **Show the floating toolbar when tickets are selected** — default on.
  Turn off if you only want the inline-edit feature and don't need the
  bottom-of-screen toolbar.

**Inline editing**

- **Edit ticket fields directly from the list view** — default on.
  When on, clicking an editable cell on the ticket list opens a small
  inline editor for that field. When **off**, clicking an empty
  editable cell (an unassigned technician, a blank category, etc.)
  opens the ticket instead — restoring useful behavior to cells that
  GLPI's stock UI leaves dead-on-click. Cells with content keep their
  default `<a>` link, so they navigate to the ticket as before.

- **Open ticket when clicking the empty area of a row** — default off.
  When on, clicking the gray/white background of a row (anywhere not on
  a clickable cell, button, link, checkbox, or inline editor) opens the
  ticket. If inline editing is also on, editable cells still trigger
  inline edit; immutable cells still open the ticket as before.

You can mix and match the toolbar and inline-edit toggles freely:

| Toolbar | Inline edit | Result |
| --- | --- | --- |
| on  | on  | Default — toolbar + click-to-edit everywhere |
| on  | off | Toolbar only; empty editable cells open the ticket |
| off | on  | Click-to-edit only; no floating toolbar |
| off | off | Toolbar and click-to-edit both gone; empty editable cells still open the ticket (otherwise plain GLPI behavior) |

### Available fields (this version)

Three widget shapes:

- **Dropdown** — picking a value commits immediately (Status, Priority,
  Assigned To, etc.).
- **Confirm-button input** — text/date/numeric. Commit happens when you
  click the green ✓ button or press **Enter** in the input. Typing alone
  does not fire any AJAX.
- **Confirm-button textarea** — multi-line text (Description). Commit
  happens when you click ✓ or press **Ctrl+Enter** / **Cmd+Enter**.
  Plain **Enter** inserts a newline. When opened inline on a ticket,
  the textarea pre-fills with the current description converted to
  plain text (formatting is dropped — see the field's notes below).
- **Multi-select** — autocomplete that searches existing tickets. Each
  pick adds one link, then clears so you can pick another.

| Field | Shape | Notes |
| --- | --- | --- |
| Title | Text | Writes to `tickets.name`. Empty values rejected. |
| Description | Textarea | Writes to `tickets.content`. Inline-edit pre-fills with the current description as plain text; line breaks in your edit are converted to `<br>` on save. **Rich formatting (bold, lists, links) is not preserved** — use the full ticket form for that. |
| Status | Dropdown | New, Processing, Pending, Solved, Closed |
| Priority | Dropdown | Very Low … Major |
| Urgency | Dropdown | 1 … 5 |
| Impact | Dropdown | 1 … 5 |
| Category | Dropdown | ITIL category (helpdesk-visible only) |
| Time to Resolve | Date | datetime-local input. Empty value clears the SLA target. |
| Total Cost | Numeric | Writes to `cost_fixed` (see below). |
| Pending Reason | Dropdown | Sets reason **and** flips status to Pending |
| Assigned To — Technician | Dropdown | Replaces existing technician (Zammad-style) |
| Requester — Requester | Dropdown | Replaces existing requester |
| Observer — Observer | Dropdown | Replaces existing observer |
| Linked Tickets — Parent Tickets | Multi-select | Adds Ticket_Ticket SON_OF links |
| Linked Tickets — Child Tickets | Multi-select | Adds Ticket_Ticket PARENT_OF links |

---

## Inline editing

Any field that's **enabled in the toolbar config** is also editable
inline on the ticket list — provided the **Edit ticket fields directly
from the list view** toggle (under the Inline editing settings, see
above) is on. With that toggle off, cells are not click-to-edit and
the section below doesn't apply; an empty editable cell instead opens
the ticket on click (cells with content keep their default link).

When inline edit is on, click an editable cell (cursor turns into a
text caret on hover) and the cell turns into a small popover holding
the same widget the toolbar uses — a text input, date picker, numeric
input, dropdown, or autocomplete.

- Text / date / numeric: confirm with the green ✓ button or **Enter**;
  cancel with ✕ or **Escape**.
- Dropdowns and autocompletes: pick a value and the change commits
  immediately. **Escape** or clicking outside cancels.
- Only one cell is in edit mode at a time. Opening a second editor
  cancels the first.

Two cell categories never enter edit mode and keep their default
"open the ticket" behavior:

- **Always immutable** — Ticket ID, Opening Date, Last Update, and
  other system-managed identifiers/timestamps.
- **Not enabled in the toolbar** — even if the field is in the catalog,
  if the admin hasn't enabled it in the toolbar config it stays
  read-only inline. Enable it in **Setup > Quick Ticket Assignment**
  to make it editable.

Two fields are deliberately **toolbar-only**, never inline-editable:
**Parent Tickets** and **Child Tickets**. The multi-add semantics
("each pick adds one link") don't translate cleanly to a single-cell
editor.

After a successful inline edit the row is re-fetched and re-rendered
exactly the same way the toolbar refresh works, so any cascading
changes (assignee group, computed totals, etc.) show up immediately.

### Tweaking the rules

The cell-classification rules live in **one place** at the top of
`public/js/quickassign.js`, in the `INLINE_RULES` object:

```javascript
var INLINE_RULES = {
    // GLPI search-option numbers that can never be edited inline.
    IMMUTABLE_SEARCH_OPTS: [2, 15, 16, 17, 18, 19, 42, 45, 121],

    // GLPI search-option number  →  catalog field key.
    EDITABLE_SEARCH_OPT_MAP: { 1: 'name', 12: 'status', /* … */ },

    // Lowercased <th> text       →  catalog field key.
    // Used as a fallback when a column has no recognizable
    // search-option number.
    EDITABLE_HEADER_MAP: { 'title': 'name', 'status': 'status', /* … */ },
};
```

If your install uses a different language or a custom column header
that isn't matched, add it to `EDITABLE_HEADER_MAP`. If a system
column you treat as immutable isn't in `IMMUTABLE_SEARCH_OPTS` yet,
add it there. Reload the page and the change takes effect.

---

## How actor fields work (Assignee, Requester, Observer)

GLPI tickets can have multiple actors of each type. To mirror Zammad's
single-actor behavior, the plugin **replaces** any existing actors of the
same type when you pick a value.

If you'd rather **add** without removing, edit `ajax/update.php` and remove
the deletion loop in the actor-fields branch:

```php
$existing = $tu->find([
    'tickets_id' => $id,
    'type'       => $actor_type,
]);
foreach ($existing as $row) {
    $tu->delete(['id' => $row['id']], true);
}
```

## How Pending Reason works

Picking a Pending Reason has two effects on each selected ticket:

1. The reason is recorded in `glpi_pendingreasons_items` (any pre-existing
   pending reason on the ticket is replaced).
2. The ticket status is set to **Pending (4)**.

Both happen in the same request. Status changes are subject to GLPI's
normal lifecycle rules.

## How "Total Cost" maps to GLPI columns

GLPI tickets have three editable cost columns — `cost_time`, `cost_fixed`,
`cost_material` — and a *computed* "Total cost" search option. There is
no editable total column to write to. The toolbar's **Total Cost** field
writes to **`cost_fixed`** because it's the simplest single-number target.

To target a different column instead, change two places:

1. `inc/config.class.php` — the `cost_fixed` catalog entry's
   `update_field`.
2. `ajax/update.php` — replace `cost_fixed` in `$allowed_fields` and the
   matching branch in the per-ticket loop.

## How parent/child ticket links work

The two **Linked Tickets** multi-selects each commit one
`glpi_tickets_tickets` row per pick:

- **Parent Tickets** — picking ticket P on selected ticket T inserts
  `(tickets_id_1=T, tickets_id_2=P, link=Ticket_Ticket::SON_OF)`, i.e.
  T becomes a son of P (P is parent of T).
- **Child Tickets** — picking ticket C on selected ticket T inserts
  `(tickets_id_1=T, tickets_id_2=C, link=Ticket_Ticket::PARENT_OF)`,
  i.e. T becomes a parent of C.

The Select2 widget self-clears after each pick so the user can chain
several adds without ever holding stale chips. Self-links are blocked
by both the autocomplete (which omits the currently selected ticket
IDs from results) and the update endpoint. Existing links — in either
direction — return as `failed` with the reason `"link already exists"`,
which surfaces in the toolbar status bar.

---

## Permissions

The toolbar's update endpoint enforces:

- The session must belong to a logged-in user.
- The user must hold the standard **Ticket > Update** right.
- The user must pass `Ticket::canUpdateItem()` for each individual ticket
  (so per-entity / per-profile rules are respected).

The config page enforces:

- **config > UPDATE** (the standard "Setup admin" right).

Tickets the user can't update are reported back as `failed` and skipped.

---

## Files

```
quickassign/
├── setup.php                       Plugin metadata, init, hooks
├── hook.php                        Install (create + seed) / uninstall (drop)
├── README.md                       This file
├── inc/
│   └── config.class.php            PluginQuickassignConfig: catalog + get/set
├── front/
│   └── config.form.php             Admin config page (both routes land here)
├── ajax/
│   ├── update.php                  Apply field changes to ticket(s)
│   ├── config.php                  Active-fields descriptor for the toolbar
│   ├── config_save.php             Save endpoint for the config page
│   ├── users.php                   Assignable users (assign/requester/observer)
│   ├── categories.php              ITIL categories (Select2)
│   ├── pendingreasons.php          Pending reasons (Select2)
│   ├── ticket_content.php          One ticket's description, for inline-edit prefill
│   └── tickets.php                 Ticket autocomplete for parent/child links
└── public/                         GLPI 11 serves static assets from here
    ├── js/
    │   └── quickassign.js          Toolbar UI + AJAX
    └── css/
        └── quickassign.css         Toolbar + config page styling
```

**About the `public/` directory** — GLPI 11 serves plugin static files from
`public/`, but the `/public/` segment is stripped from the URL. So
`quickassign/public/js/quickassign.js` is served at
`/plugins/quickassign/js/quickassign.js`. PHP scripts under `/ajax/`,
`/front/`, and `/inc/` are served from their on-disk paths.

---

## Database

The plugin owns two tables.

**`glpi_plugin_quickassign_fields`** — the active toolbar field set.

```
id            INT auto_increment PK
field_key     VARCHAR(64)  unique  -- catalog key, e.g. '_itil_assign'
display_order INT          unique  -- 1..N, position in the toolbar
```

It stores **only the active fields**. The catalog of available field types
lives in `inc/config.class.php`. Install seeds four rows (the legacy
default toolbar); uninstall drops the table.

**`glpi_plugin_quickassign_settings`** — plugin-wide settings as a
key/value bag.

```
id            INT auto_increment PK
setting_key   VARCHAR(64)  unique
setting_value VARCHAR(255)
```

Currently used keys:

- `enable_toolbar` — `'0'` or `'1'`. When `'1'` (default), the floating
  toolbar renders at the bottom of the ticket list when tickets are
  selected. Added in 1.5.0.
- `enable_inline_edit` — `'0'` or `'1'`. When `'1'` (default), clicking
  an editable cell in the ticket list opens an inline editor. When `'0'`,
  the JS instead wires a "click an empty editable cell to open the
  ticket" fallback so previously-editable empty cells don't go inert.
  Added in 1.5.0.
- `row_background_opens_ticket` — `'0'` or `'1'`. When `'1'`, clicking
  the empty area of a ticket row opens the ticket. Default `'0'`.

Defaults are applied in PHP (see
`PluginQuickassignConfig::getSettingDefaults()`), so a missing row falls
back cleanly — pre-1.5 installs that have no rows for the new keys keep
the same on/on behavior they had before. Install creates the table
empty; uninstall drops it.

Upgrading a pre-1.4 install: existing field rows are kept as-is, the
settings table is created on first install/upgrade, and new field
types are picked up automatically — the admin can enable them at
**Setup > Quick Ticket Assignment**.

---

## Customization tips

- **Adding a new dropdown-shaped field** — add an entry to
  `PluginQuickassignConfig::getCatalog()`, add the same key to
  `$allowed_fields` in `ajax/update.php`, and (if it needs a custom
  AJAX endpoint) drop a Select2-format script under `ajax/`.
- **Adding a new text/date/numeric field** — add a catalog entry with
  `'render' => 'text' | 'date' | 'numeric'` and your `update_field`,
  then add a matching branch in `ajax/update.php`'s per-ticket loop.
  The JS already handles the widget shape generically.
- **Changing the assignee filter** — adjust the `assign` branch of
  `ajax/users.php`. The Ticket::OWN bitmask check is what limits the
  list to users who can be technicians.
- **Switching Total Cost to cost_time / cost_material** — see the
  "Total Cost" section above.
- **Tweaking which columns are inline-editable** — edit the
  `INLINE_RULES` object near the top of `public/js/quickassign.js`.
  See the "Inline editing > Tweaking the rules" section above.
- **Refresh strategy** — after a successful update the plugin re-fetches
  the current URL via AJAX and swaps the new `.search-card` contents in
  place, preserving search filters, sort, and pagination. To disable and
  fall back to GLPI's auto-refresh timer, comment out the
  `refreshTicketList(...)` call in `commitFieldUpdate`'s `.done` handler.
- **Page detection** — the script only activates when the URL ends in
  `/front/ticket.php` and there's no `?id=` parameter.

---

## Troubleshooting

- **Toolbar doesn't appear** — open the browser console. Common causes:
  jQuery, Select2, or `CFG_GLPI` not available; `ajax/config.php` returning
  an empty fields list (no fields enabled in config); or the plugin not
  enabled.
- **Config page 403** — the user lacks the `config / UPDATE` right.
- **Assignee dropdown is empty** — your active entity may have no users
  with the "Be in charge of a ticket" right. Check `ajax/users.php`'s
  `assign` branch SQL.
- **Pending Reason has no effect** — make sure your GLPI version exposes
  the `PendingReason_Item` class (GLPI 10+). Check
  `files/_log/php-errors.log` for messages tagged `[quickassign]`.
- **403 from the update endpoint** — the calling user lacks the
  `ticket > UPDATE` right at the profile level.
- **A column I expected to be editable opens the ticket instead** —
  either the field isn't enabled in the toolbar config (enable it at
  **Setup > Quick Ticket Assignment**) or the column header / search
  option isn't in `INLINE_RULES`. Add it to `EDITABLE_HEADER_MAP` or
  `EDITABLE_SEARCH_OPT_MAP` in `public/js/quickassign.js`.
- **A column I want to keep read-only is editable** — add its GLPI
  search-option number to `IMMUTABLE_SEARCH_OPTS` in
  `public/js/quickassign.js`. The immutable list wins over the
  editable list.

---

## License

GPLv3, matching GLPI itself.
