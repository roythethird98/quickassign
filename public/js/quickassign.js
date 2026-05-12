/**
 * Quick Ticket Assignment - frontend
 *
 * On the Tickets list page:
 *   1. Fetch the active-fields descriptor from /ajax/config.php
 *   2. Build a floating toolbar with a dropdown per active field
 *   3. Apply chosen values to all selected tickets via /ajax/update.php
 *   4. Wire inline edit on the ticket list itself: clicking an editable
 *      cell opens an inline editor of the matching widget shape; clicking
 *      an immutable cell falls through to the existing link (open ticket).
 *   5. Optionally (config-gated): clicking the empty area of a row opens
 *      the ticket.
 *
 * Selection is preserved across the GLPI auto-refresh by tracking ticket
 * IDs in JS state and re-ticking the boxes whenever the table re-renders.
 */
(function ($) {
    'use strict';

    if (typeof $ === 'undefined') {
        return;
    }

    // ---------------------------------------------------------------------
    // Page detection: only run on the ticket LIST page (not the form view)
    // ---------------------------------------------------------------------
    function isTicketListPage() {
        var path   = window.location.pathname || '';
        var params = new URLSearchParams(window.location.search || '');
        var onTicketPath = /\/front\/ticket\.php$/.test(path);
        var isFormView   = params.has('id');
        return onTicketPath && !isFormView;
    }

    if (!isTicketListPage()) {
        return;
    }

    var rootDoc = (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.root_doc) ? CFG_GLPI.root_doc : '';

    // The descriptor returned by ajax/config.php — populated once per page
    // load and reused everywhere the toolbar's structure is needed.
    var fieldConfig = null;
    var pluginSettings = {}; // populated alongside fieldConfig from config.php

    // -----------------------------------------------------------------
    // v1.4.1 — refresh-in-flight guard.
    //
    // Goes true the moment we detect the search-card is being rebuilt
    // (either by us or by GLPI's auto-refresh), and goes false a short
    // grace period after restoreSelectionToDom has run. While true, the
    // document-level checkbox change/click handler MUST NOT re-read the
    // DOM into selectedIds — the DOM is mid-rebuild and reading it
    // produces a false-empty snapshot that wipes the toolbar.
    //
    // Without this, GLPI 11's AJAX list refresh would fire change events
    // on the freshly-inserted (initially-unchecked) checkboxes, causing
    // the 50ms-delayed syncSelectionFromDom to win the race against the
    // 150ms-delayed restoreSelectionToDom and clear our selection.
    // -----------------------------------------------------------------
    var refreshInFlight = false;

    // Opt-in console diagnostics. Set window.qaDebug = true before
    // refresh, or paste `window.qaDebug = true` into devtools.
    function qaLog() {
        if (!window.qaDebug) return;
        var args = ['[quickassign]'].concat(Array.prototype.slice.call(arguments));
        try { console.log.apply(console, args); } catch (e) { /* ignore */ }
    }

    // =====================================================================
    // INLINE-EDIT RULES
    //
    // Single source of truth for "which cells are editable in place vs. open
    // the ticket on click". Edit this object to tweak per-installation
    // behavior — the rest of the inline-edit code reads from it.
    //
    // Resolution order for a clicked TD:
    //   1. If the TD's column maps to a search-opt number in
    //      IMMUTABLE_SEARCH_OPTS → cell is immutable. Click does nothing
    //      (the existing <a> link in the cell opens the ticket as usual).
    //   2. Else if the column maps to a key in EDITABLE_SEARCH_OPT_MAP and
    //      that key is in the active toolbar config → cell is editable.
    //      Click enters inline edit.
    //   3. Else if the lowercased TH text matches a key in
    //      EDITABLE_HEADER_MAP and that field is active → editable.
    //      (Locale fallback for installs where data-searchopt-num isn't on
    //      the cell/header — e.g. older GLPI 11 builds.)
    //   4. Otherwise → not editable. Click does nothing.
    //
    // Search-option numbers are GLPI-stable identifiers from Ticket's
    // SearchOption catalog (see Ticket::rawSearchOptions). They're
    // locale-independent and the most reliable signal.
    // ---------------------------------------------------------------------
    var INLINE_RULES = {
        // Columns that always open the ticket, regardless of catalog
        // membership. System-managed identifiers and timestamps.
        IMMUTABLE_SEARCH_OPTS: [
            2,   // ID
            15,  // Opening date
            16,  // Closing date
            17,  // Resolution date
            18,  // Time-to-resolve in some configurations is read-only
                 // (it's recomputed). The editable copy lives at
                 // ticket.time_to_resolve which IS search opt 18 — but
                 // when the column shows the SLA-driven *target*, it's
                 // recomputed by core. We keep it editable below; remove
                 // it from EDITABLE_SEARCH_OPT_MAP if your install treats
                 // it as system-managed.
            19,  // Last update (date_mod)
            42,  // Cost - Total cost (computed = fixed + time + material)
            45,  // Group of requester (depends on install)
            121, // date_creation, alternative search opt seen on some installs
        ],

        // Editable columns: search-opt num → catalog field key.
        EDITABLE_SEARCH_OPT_MAP: {
            1:   'name',                  // Title
            3:   'priority',
            4:   '_itil_requester',       // Requester (actor)
            5:   '_itil_assign',          // Technician (actor)
            7:   'itilcategories_id',     // Category
            10:  'urgency',
            11:  'impact',
            12:  'status',
            18:  'time_to_resolve',       // SLA target — see note above
            21:  'content',               // Description (Ticket search opt)
            43:  'cost_fixed',            // Cost - Fixed
            66:  '_itil_observer',        // Observer (actor) — search opt varies
            142: 'pendingreasons_id'      // Pending reason (estimated; see fallback)
        },

        // Locale-fallback by lowercased header text. Used when the cell
        // has no resolvable searchopt num. Extend per locale if needed.
        EDITABLE_HEADER_MAP: {
            'title':                  'name',
            'name':                   'name',
            'description':            'content',
            'content':                'content',
            'priority':               'priority',
            'status':                 'status',
            'urgency':                'urgency',
            'impact':                 'impact',
            'category':               'itilcategories_id',
            'requester':              '_itil_requester',
            'requester - requester':  '_itil_requester',
            'assigned to':            '_itil_assign',
            'technician':             '_itil_assign',
            'observer':               '_itil_observer',
            'time to resolve':        'time_to_resolve',
            'pending reason':         'pendingreasons_id',
            'total cost':             'cost_fixed',
            'cost - fixed':           'cost_fixed',
            'fixed cost':             'cost_fixed'
        }
    };
    // =====================================================================

    $(function () { init(); });

    // ---------------------------------------------------------------------
    // Init: fetch config, then start the checkbox-detection loop. Without
    // a config we have nothing to render, so we bail early on either an
    // empty list or a failed fetch.
    // ---------------------------------------------------------------------
    function init() {
        $.ajax({
            url: rootDoc + '/plugins/quickassign/ajax/config.php',
            type: 'GET',
            dataType: 'json',
            cache: false
        })
        .done(function (data) {
            if (!data || !data.fields || data.fields.length === 0) {
                return;
            }
            fieldConfig    = data.fields;
            pluginSettings = data.settings || {};
            startWhenReady();
        })
        .fail(function (xhr) {
            console.warn('[quickassign] Failed to load config:', xhr.status, xhr.statusText);
        });
    }

    // Wait until at least one ticket checkbox exists. GLPI 11 sometimes
    // renders the search results asynchronously, so we poll briefly.
    function startWhenReady() {
        var tries = 0;
        var iv = setInterval(function () {
            tries++;
            if (findTicketCheckboxes().length > 0 || tries > 20) {
                clearInterval(iv);
                if (findTicketCheckboxes().length > 0) {
                    // v1.5.0: Feature toggles. Defaults are TRUE for both,
                    // so an admin who never visits the config page (or who
                    // upgraded from <1.5.0 before the keys existed) keeps
                    // the same behavior. `!== false` matches that policy —
                    // undefined / true / "1" all activate; only an explicit
                    // false suppresses.
                    var wantToolbar    = (pluginSettings.enable_toolbar     !== false);
                    var wantInlineEdit = (pluginSettings.enable_inline_edit !== false);

                    if (wantToolbar) {
                        buildToolbar();
                        bindEvents();
                        syncSelectionFromDom();
                    }

                    if (wantInlineEdit) {
                        // Click-to-edit on cells in the ticket list.
                        setupInlineEdit();
                    } else {
                        // Inline edit is off, but we still want empty
                        // editable cells (e.g. an unassigned technician
                        // column) to be useful — GLPI's default leaves
                        // them dead (no <a> = nothing happens on click).
                        // setupEmptyCellClickThrough makes them open the
                        // ticket. Cells with content keep their existing
                        // <a> link, so they're unaffected.
                        setupEmptyCellClickThrough();
                    }

                    if (pluginSettings.row_background_opens_ticket) {
                        setupRowBackgroundClick();
                    }
                }
            }
        }, 250);
    }

    // ---------------------------------------------------------------------
    // Find checkboxes that represent tickets in the list.
    // GLPI uses several selector patterns over versions; we try a few.
    // ---------------------------------------------------------------------
    function findTicketCheckboxes(checkedOnly) {
        var selectors = [
            'input[name="item[Ticket][]"]',
            'input[name^="item[Ticket]["]',
            'input.massive_action_checkbox[data-glpi-massiveaction-itemtype="Ticket"]',
            'tr[data-itemtype="Ticket"] input[type="checkbox"]'
        ];
        var sel = selectors.join(',');
        if (checkedOnly) {
            sel = selectors.map(function (s) { return s + ':checked'; }).join(',');
        }
        return $(sel);
    }

    // ---------------------------------------------------------------------
    // Build toolbar from fieldConfig. Six widget shapes:
    //   static / ajax_user / ajax_dropdown — Select2 single, commits on change
    //   ajax_ticket                        — Select2 single, commits on pick
    //                                        then auto-clears (multi-add UX)
    //   text / numeric                     — <input> + confirm button
    //   date                               — <input type=datetime-local>
    //                                        + confirm button
    //
    // For confirm-button widgets, ONLY the button or the Enter key fires
    // the AJAX call. Typing or focus/blur does not commit.
    // ---------------------------------------------------------------------
    function buildToolbar() {
        if ($('#quickassign-toolbar').length) return;

        var fieldsHtml = '';
        for (var i = 0; i < fieldConfig.length; i++) {
            var f = fieldConfig[i];
            var idSuffix = sanitizeIdSuffix(f.key);

            var inner = renderEditorMarkup(f, {
                inputId:   'qa-input-'  + idSuffix,
                selectId:  'qa-select-' + idSuffix,
                showCancel: false  // toolbar uses the close button instead
            });

            fieldsHtml += ''
                + '<div class="qa-field qa-field-' + escapeAttr(f.render) + '" '
                +      'data-key="' + escapeAttr(f.key) + '" '
                +      'data-render="' + escapeAttr(f.render) + '">'
                +   '<label>' + escapeHtml(f.label) + '</label>'
                +   inner
                + '</div>';
        }

        var html = ''
            + '<div id="quickassign-toolbar" class="quickassign-toolbar" style="display:none;">'
            +   '<div class="qa-inner">'
            +     '<div class="qa-info"><span class="qa-count">0</span> selected</div>'
            +     fieldsHtml
            +     '<button type="button" class="qa-close" title="Clear selection">&times;</button>'
            +   '</div>'
            +   '<div class="qa-status-msg"></div>'
            + '</div>';

        $('body').append(html);

        initSelect2Dropdowns();

        // Select-driven fields (existing behavior): change → commit.
        $('#quickassign-toolbar').on('change', '.qa-select', handleToolbarFieldChange);

        // Confirm-button widgets: button click OR Enter inside the input.
        $('#quickassign-toolbar').on('click', '.qa-confirm', handleToolbarConfirmClick);
        $('#quickassign-toolbar').on('keydown', '.qa-input', function (e) {
            if (!isCommitKey(e, this)) return;
            // Commit shortcut pressed. preventDefault so a stray <form>
            // ancestor (some GLPI page wraps the body) doesn't submit
            // on Enter, and so plain Enter inside a textarea doesn't
            // ALSO insert a newline (we only reach this when Ctrl/Cmd
            // is held, but be defensive).
            e.preventDefault();
            var key = $(this).data('key');
            $('#quickassign-toolbar .qa-confirm[data-key="'
                + cssEscape(key) + '"]').trigger('click');
        });

        $('#quickassign-toolbar').on('click', '.qa-close', clearSelection);
    }

    // ---------------------------------------------------------------------
    // Is this keydown event a "commit" gesture for the given input?
    //
    //   <input>     → plain Enter commits (single-line; nothing else to do)
    //   <textarea>  → Ctrl+Enter or Cmd+Enter commits; plain Enter inserts
    //                 a newline (default browser behavior). This matches
    //                 the common "send the form" convention from chat
    //                 apps, GitHub comment boxes, etc.
    // ---------------------------------------------------------------------
    function isCommitKey(e, el) {
        var isEnter = (e.key === 'Enter' || e.keyCode === 13);
        if (!isEnter) return false;
        var tag = (el && el.tagName) ? el.tagName.toLowerCase() : '';
        if (tag === 'textarea') {
            return !!(e.ctrlKey || e.metaKey);
        }
        return true;
    }

    // ---------------------------------------------------------------------
    // Build the editor element(s) for a field. Used by both the toolbar
    // (which embeds the markup once at startup) and the inline editor
    // (which builds it on demand inside a clicked TD).
    //
    // Options:
    //   inputId    string   id for the <input> in text/date/numeric shapes
    //   selectId   string   id for the <select> in dropdown shapes
    //   showCancel bool     also render a ✕ cancel button next to ✓
    //                       (used by inline edit; toolbar has its own close)
    //
    // Returns an HTML string suitable for either innerHTML or $.append().
    // ---------------------------------------------------------------------
    function renderEditorMarkup(f, options) {
        options = options || {};
        var inputId  = options.inputId  || 'qa-input';
        var selectId = options.selectId || 'qa-select';
        var cancelBtn = options.showCancel
            ? '<button type="button" class="qa-cancel" '
                + 'data-key="' + escapeAttr(f.key) + '" '
                + 'title="Cancel">&times;</button>'
            : '';

        if (f.render === 'text' || f.render === 'numeric' || f.render === 'date') {
            // text / numeric / date: input + confirm (+ optional cancel)
            var inputType = (f.render === 'date')    ? 'datetime-local'
                          : (f.render === 'numeric') ? 'number'
                          :                            'text';
            var attrs = '';
            if (f.placeholder) attrs += ' placeholder="' + escapeAttr(f.placeholder) + '"';
            if (f.step != null) attrs += ' step="' + escapeAttr(f.step) + '"';
            if (f.min  != null) attrs += ' min="'  + escapeAttr(f.min)  + '"';
            if (f.max  != null) attrs += ' max="'  + escapeAttr(f.max)  + '"';

            return ''
                + '<div class="qa-input-row">'
                +   '<input type="' + inputType + '" '
                +         'id="' + escapeAttr(inputId) + '" '
                +         'class="qa-input form-control" '
                +         'data-key="' + escapeAttr(f.key) + '"' + attrs + '>'
                +   '<button type="button" class="qa-confirm btn btn-primary" '
                +           'data-key="' + escapeAttr(f.key) + '" '
                +           'title="Apply">&#10003;</button>'
                +   cancelBtn
                + '</div>';
        }

        if (f.render === 'textarea') {
            // textarea: multi-line input + confirm (+ optional cancel).
            //
            // The textarea carries the same `qa-input` class as the
            // text/numeric/date inputs so the existing selectors
            // (.qa-input for value reads, .qa-input keydown for commit)
            // pick it up without special casing. The keydown handler
            // does inspect the element type and only commits on
            // Ctrl/Cmd+Enter for textareas (plain Enter inserts a
            // newline as a user would expect).
            var taAttrs = '';
            if (f.placeholder) taAttrs += ' placeholder="' + escapeAttr(f.placeholder) + '"';
            var rows = parseInt(f.rows, 10);
            if (isNaN(rows) || rows < 1) rows = 4;
            taAttrs += ' rows="' + rows + '"';

            return ''
                + '<div class="qa-input-row qa-input-row-textarea">'
                +   '<textarea id="' + escapeAttr(inputId) + '" '
                +             'class="qa-input qa-input-textarea form-control" '
                +             'data-key="' + escapeAttr(f.key) + '"' + taAttrs + '></textarea>'
                +   '<div class="qa-input-row-buttons">'
                +     '<button type="button" class="qa-confirm btn btn-primary" '
                +             'data-key="' + escapeAttr(f.key) + '" '
                +             'title="Apply (Ctrl+Enter)">&#10003;</button>'
                +     cancelBtn
                +   '</div>'
                + '</div>';
        }

        // everything Select2-driven (static + all ajax_*)
        var selectInner = '<option value=""></option>';
        if (f.render === 'static' && f.options) {
            for (var j = 0; j < f.options.length; j++) {
                var opt = f.options[j];
                selectInner += '<option value="' + escapeAttr(opt.id) + '">'
                            + escapeHtml(opt.text) + '</option>';
            }
        }
        var selectHtml = '<select id="' + escapeAttr(selectId) + '" '
                       + 'class="qa-select" '
                       + 'data-key="' + escapeAttr(f.key) + '">'
                       + selectInner
                       + '</select>';
        if (cancelBtn) {
            // Wrap in a flex row so the cancel ✕ sits next to the select
            // without disrupting Select2's width:100% behavior.
            return '<div class="qa-input-row">' + selectHtml + cancelBtn + '</div>';
        }
        return selectHtml;
    }

    function sanitizeIdSuffix(key) {
        return String(key).replace(/[^a-z0-9_-]/gi, '_');
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    function escapeAttr(s) { return escapeHtml(s); }

    // ---------------------------------------------------------------------
    // Wrap each <select> in Select2 so the rendering (font, padding, arrow)
    // is consistent across static and AJAX-driven fields. Without it, the
    // native <select> for static fields falls back to OS chrome and looks
    // different from the AJAX dropdowns next to it.
    //
    // Skips text/date/numeric fields — those don't have a <select>.
    // ---------------------------------------------------------------------
    function initSelect2Dropdowns() {
        if (typeof $.fn.select2 === 'undefined') {
            // Fall back to plain <select>s. AJAX-backed fields will be
            // empty in this state, but static ones still work.
            return;
        }

        for (var i = 0; i < fieldConfig.length; i++) {
            var f = fieldConfig[i];
            if (f.render === 'text' || f.render === 'numeric'
                || f.render === 'date' || f.render === 'textarea') {
                continue; // not a Select2 widget
            }
            var $sel = $('#quickassign-toolbar .qa-select[data-key="' + cssEscape(f.key) + '"]');
            initSelect2For($sel, f, { context: 'toolbar' });
        }
    }

    // ---------------------------------------------------------------------
    // Init Select2 on a single element. Used by both the toolbar and the
    // inline editor — the only inline-edit-specific knob is `excludeIds`,
    // which the ajax_ticket handler uses to keep the picked ticket from
    // self-linking.
    //
    // Options:
    //   context    'toolbar' | 'inline'   (cosmetic — placeholder text)
    //   excludeIds array of ints           tickets to omit from ajax_ticket
    //                                      results (defaults to selectedIds
    //                                      for 'toolbar', the editing
    //                                      ticket's id for 'inline')
    //   dropdownParent jQuery              optional — where Select2 attaches
    //                                      its panel. Default is body via
    //                                      Select2's auto-positioning.
    // ---------------------------------------------------------------------
    function initSelect2For($sel, f, options) {
        if (!$sel || !$sel.length || typeof $.fn.select2 === 'undefined') return;
        options = options || {};

        var common = {
            placeholder: '— Change —',
            allowClear: true,
            width: '100%'
            // No dropdownParent: lets Select2 attach to <body> and auto-flip
            // upward since the toolbar sits at the viewport bottom.
        };
        if (options.dropdownParent) {
            common.dropdownParent = options.dropdownParent;
        }

        if (f.render === 'static') {
            // Static lists are short — hide the search box (just visual
            // noise) and hide the empty placeholder option from the open
            // dropdown.
            $sel.select2($.extend({}, common, {
                minimumResultsForSearch: -1,
                templateResult: function (data) {
                    return data.id ? data.text : null;
                }
            }));
            return;
        }

        // ajax_user / ajax_dropdown / ajax_ticket: same Select2 wiring,
        // just different endpoints and per-shape extras (actor for users,
        // exclude-list for tickets).
        var placeholderOverride = (f.render === 'ajax_ticket')
            ? (options.context === 'inline' ? '— Link ticket —' : '— Add ticket —')
            : common.placeholder;

        $sel.select2($.extend({}, common, {
            placeholder: placeholderOverride,
            minimumInputLength: 0,
            ajax: {
                url: f.endpoint,
                dataType: 'json',
                delay: 250,
                type: 'POST',
                data: function (params) {
                    var payload = {
                        searchText: params.term || '',
                        page: params.page || 1
                    };
                    if (f.actor) payload.actor = f.actor;
                    if (f.render === 'ajax_ticket') {
                        // Anti-self-link: the endpoint omits these from
                        // its results. Sent as comma-joined string for
                        // simpler $_POST handling.
                        var excl = options.excludeIds
                                || (options.context === 'inline' ? [] : (selectedIds || []));
                        payload.exclude = excl.join(',');
                    }
                    return payload;
                },
                processResults: function (data) {
                    if (!data || !data.results) {
                        console.warn('[quickassign] Unexpected response for ' + f.key + ':', data);
                        return { results: [] };
                    }
                    return data;
                },
                error: function (xhr) {
                    console.error('[quickassign] Dropdown failed (' + f.key + '):',
                        xhr.status, xhr.statusText, xhr.responseText);
                }
            }
        }));
    }

    // CSS.escape isn't on every supported browser; the field keys we ship
    // are simple ([a-z_]) but underscores and the leading underscore in
    // "_itil_assign" need no escaping anyway. We still wrap in case a
    // future field key adds something exotic.
    function cssEscape(s) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(s);
        }
        return String(s).replace(/(["\\\]\[#.])/g, '\\$1');
    }

    // ---------------------------------------------------------------------
    // Extract the ticket ID from a single checkbox element. GLPI uses two
    // different patterns depending on version:
    //   Newer (GLPI 10+/11):  <input name="item[Ticket][42]" value="1">
    //   Older:                <input name="item[Ticket][]"  value="42">
    // The newer form's value is always "1" (just the standard "I am checked"
    // marker), so we MUST read the name first or every ticket looks like
    // ticket #1.
    // ---------------------------------------------------------------------
    function getTicketIdFrom(el) {
        var name = el.getAttribute('name') || '';
        var m    = name.match(/\[Ticket\]\[(\d+)\]/);
        if (m) {
            return parseInt(m[1], 10);
        }
        // Fallback for older GLPI: the ID lives in the value.
        var v = el.value;
        if (v && /^\d+$/.test(v) && v !== '1') {
            return parseInt(v, 10);
        }
        return NaN;
    }

    // ---------------------------------------------------------------------
    // Selection state.
    //
    // We can't trust the DOM as the source of truth: GLPI auto-refreshes
    // the ticket list on a timer, replacing all the rows (and their
    // checkboxes) with fresh ones. So we keep our own array of selected
    // ticket IDs in JS, sync it from the DOM whenever the user clicks a
    // checkbox, and write it back to the DOM whenever the table re-renders.
    // ---------------------------------------------------------------------
    var selectedIds = [];
    var restoreTimer = null;

    function readSelectionFromDom() {
        var ids = [];
        findTicketCheckboxes(true).each(function () {
            var id = getTicketIdFrom(this);
            if (!isNaN(id)) ids.push(id);
        });
        return ids.filter(function (v, i, a) { return a.indexOf(v) === i; });
    }

    // User clicked a checkbox: pull the current DOM state into our cache.
    function syncSelectionFromDom() {
        selectedIds = readSelectionFromDom();
        updateToolbar();
    }

    // Table got re-rendered (new checkbox elements): re-tick the boxes for
    // tickets we still consider selected. Drop any IDs that are no longer
    // visible on the page (e.g. moved to a different page by the refresh).
    function restoreSelectionToDom() {
        if (selectedIds.length === 0) return;

        var stillSelected = [];
        findTicketCheckboxes().each(function () {
            var id = getTicketIdFrom(this);
            if (!isNaN(id) && selectedIds.indexOf(id) >= 0) {
                this.checked = true;
                stillSelected.push(id);
            }
        });

        selectedIds = stillSelected;
        updateToolbar();
    }

    function updateToolbar() {
        var $tb = $('#quickassign-toolbar');
        $tb.find('.qa-count').text(selectedIds.length);
        if (selectedIds.length > 0) {
            $tb.show();
            $('body').css('padding-bottom', $tb.outerHeight() + 16 + 'px');
        } else {
            $tb.hide();
            $('body').css('padding-bottom', '');
            // Reset any dropdown values silently (no change handler fires
            // because of the early `if (!value)` bail-out).
            $tb.find('.qa-select').val('').trigger('change.select2');
            // Clear the text/date/numeric inputs too. No event needed —
            // these only commit on confirm-click / Enter.
            $tb.find('.qa-input').val('');
        }
    }

    // Briefly highlight rows that were just updated so the user can see
    // which ones the action affected, even before the table refreshes.
    function flashUpdatedRows(ids) {
        if (!ids || ids.length === 0) return;
        findTicketCheckboxes().each(function () {
            var id = getTicketIdFrom(this);
            if (!isNaN(id) && ids.indexOf(id) >= 0) {
                var $row = $(this).closest('tr');
                $row.addClass('qa-row-updated');
                setTimeout(function () { $row.removeClass('qa-row-updated'); }, 1800);
            }
        });
    }

    // ---------------------------------------------------------------------
    // Refresh the ticket list on demand.
    //
    // Re-fetches the current URL (preserving all search/sort/pagination
    // params), pulls the new .search-card out of the response, and swaps
    // its contents in place. This is the same shape as GLPI's built-in
    // auto-refresh — we just trigger it ourselves right after an update
    // instead of waiting for the timer.
    //
    // Selection is preserved automatically: the swap fires the
    // MutationObserver in setupRefreshObserver(), which calls
    // restoreSelectionToDom() to re-tick the boxes from selectedIds.
    // ---------------------------------------------------------------------
    function refreshTicketList(onComplete) {
        var $card = $('.search-card').first();
        if (!$card.length) {
            if (typeof onComplete === 'function') onComplete();
            return;
        }

        // Don't fire concurrent refreshes. If one is in flight, just call
        // the callback — by the time it runs, the in-flight refresh will
        // have produced fresh DOM that includes whatever update we just
        // made (POSTs are serialized by GLPI).
        if ($card.data('qa-refreshing')) {
            if (typeof onComplete === 'function') {
                setTimeout(onComplete, 300);
            }
            return;
        }
        $card.data('qa-refreshing', true);

        $.ajax({
            url: window.location.href,
            type: 'GET',
            dataType: 'html',
            cache: false
        })
        .done(function (html) {
            try {
                // $.parseHTML strips <script> tags by default — we don't
                // want page scripts re-executing on every refresh.
                var $temp    = $('<div>').append($.parseHTML(html));
                var $newCard = $temp.find('.search-card').first();
                if ($newCard.length) {
                    $card.empty().append($newCard.contents());

                    // The MutationObserver will also fire on this swap,
                    // but we trigger restore explicitly so the callback
                    // (typically flashUpdatedRows) lands on rows that are
                    // already re-ticked.
                    clearTimeout(restoreTimer);
                    restoreTimer = setTimeout(function () {
                        restoreSelectionToDom();
                        if (typeof onComplete === 'function') onComplete();
                    }, 100);
                    return;
                }
            } catch (e) {
                console.error('[quickassign] Refresh parse error:', e);
            }
            if (typeof onComplete === 'function') onComplete();
        })
        .fail(function (xhr) {
            console.warn('[quickassign] Refresh failed:', xhr.status, xhr.statusText);
            if (typeof onComplete === 'function') onComplete();
        })
        .always(function () {
            $card.data('qa-refreshing', false);
        });
    }

    // ---------------------------------------------------------------------
    // Events
    // ---------------------------------------------------------------------
    function bindEvents() {
        // User checks/unchecks a box → re-read DOM into our cache.
        // Both 'change' and 'click' fire — change is enough but click
        // catches edge cases with custom checkbox widgets in some themes.
        //
        // v1.4.1: scope the selector to ticket checkboxes only (not
        // EVERY checkbox on the page — GLPI 11's auto-refresh fires
        // change events on unrelated checkboxes during rebuild and they
        // shouldn't trigger a selection re-read), AND honor the
        // refreshInFlight flag so a mid-rebuild DOM snapshot doesn't
        // wipe the cached selection.
        $(document).on('change.qaSel click.qaSel',
            'input[type="checkbox"][name^="item[Ticket]"],'
          + ' input.massive_action_checkbox[data-glpi-massiveaction-itemtype="Ticket"]',
            function () {
                if (refreshInFlight) {
                    qaLog('checkbox event ignored (refresh in flight)');
                    return;
                }
                setTimeout(function () {
                    if (refreshInFlight) return;
                    syncSelectionFromDom();
                }, 50);
            });

        setupRefreshObserver();
    }

    // Watch a STABLE ANCESTOR (not the .search-card itself) for DOM
    // mutations. When the search-card or its rows are rebuilt, react.
    //
    // v1.4.1 rationale: GLPI 11's AJAX list refresh sometimes replaces
    // the .search-card element ENTIRELY (see glpi issue #21347, "Double
    // header on auto-refresh"). If we observe .search-card directly,
    // we're observing a node that gets detached and our observer never
    // fires after the first such refresh. Observing <main> (or <body>
    // as ultimate fallback) keeps us connected to a node that lives for
    // the lifetime of the page.
    //
    // We filter mutations to ones that actually affect the search-card
    // subtree so we don't react to every navbar/sidebar tweak.
    function setupRefreshObserver() {
        if (typeof MutationObserver === 'undefined') return;

        var target = document.querySelector('main')
                  || document.querySelector('#page')
                  || document.body;
        if (!target) return;

        function affectsSearchCard(mutation) {
            // Mutation inside the search-card subtree — react.
            if ($(mutation.target).closest('.search-card').length) return true;
            // Or it adds/removes a search-card itself (the "wholesale
            // replacement" case).
            function nodesContainSearchCard(nodes) {
                for (var i = 0; i < nodes.length; i++) {
                    var n = nodes[i];
                    if (n && n.nodeType === 1) {
                        if (n.matches && n.matches('.search-card')) return true;
                        if (n.querySelector && n.querySelector('.search-card')) return true;
                    }
                }
                return false;
            }
            return nodesContainSearchCard(mutation.addedNodes)
                || nodesContainSearchCard(mutation.removedNodes);
        }

        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                if (m.addedNodes.length === 0 && m.removedNodes.length === 0) continue;
                if (!affectsSearchCard(m)) continue;

                // We're in the middle of (or just observed) a list
                // rebuild. Block syncSelectionFromDom races until
                // restoreSelectionToDom has had its chance to re-tick.
                if (!refreshInFlight) {
                    qaLog('refresh detected — entering refreshInFlight');
                    refreshInFlight = true;
                }

                clearTimeout(restoreTimer);
                restoreTimer = setTimeout(function () {
                    restoreSelectionToDom();
                    // Hold the guard a little longer than the restore
                    // itself so any late checkbox events from this
                    // rebuild are still suppressed.
                    setTimeout(function () {
                        refreshInFlight = false;
                        qaLog('refreshInFlight cleared');
                    }, 200);
                }, 150);
                return;
            }
        });

        observer.observe(target, { childList: true, subtree: true });
        qaLog('setupRefreshObserver attached to', target.tagName, '.' + (target.className || ''));
    }

    // Look up the field descriptor by its key — used to find the
    // update_field name without re-deriving it on the JS side.
    function findFieldByKey(key) {
        if (!fieldConfig) return null;
        for (var i = 0; i < fieldConfig.length; i++) {
            if (fieldConfig[i].key === key) return fieldConfig[i];
        }
        return null;
    }

    function handleToolbarFieldChange() {
        var $sel  = $(this);
        var key   = $sel.data('key');
        var field = findFieldByKey(key);
        var value = $sel.val();
        if (!value || !field) return;

        if (selectedIds.length === 0) {
            showStatus('No tickets selected', 'error');
            return;
        }

        // For ajax_ticket (parent/child links), each pick is one add. We
        // clear the Select2 after the request settles so the user can
        // chain picks without ever holding stale chips. The clear fires
        // change again, but the early `if (!value)` bail-out catches it.
        var afterCommit = null;
        if (field.render === 'ajax_ticket') {
            afterCommit = function () {
                $sel.val(null).trigger('change.select2');
            };
        }

        commitFieldUpdate(field.update_field, value, selectedIds.slice(), {
            afterCommit: afterCommit,
            statusTarget: '#quickassign-toolbar .qa-status-msg'
        });
    }

    // ---------------------------------------------------------------------
    // Toolbar confirm-button click handler (text / date / numeric).
    //
    // Reads the value from the matching .qa-input, validates the absolute
    // minimum (non-empty / numeric where required), and calls the same
    // commitFieldUpdate path the dropdowns use. The button is disabled
    // for the duration of the request so a double-click can't double-fire.
    // ---------------------------------------------------------------------
    function handleToolbarConfirmClick() {
        var $btn  = $(this);
        var key   = $btn.data('key');
        var field = findFieldByKey(key);
        if (!field) return;

        var $input = $('#quickassign-toolbar .qa-input[data-key="'
                       + cssEscape(key) + '"]');
        if (!$input.length) return;

        var raw = $input.val();
        var value = (raw == null) ? '' : String(raw).trim();

        // Per-shape minimum validation. The server validates again — this
        // is purely UX (avoid an obviously-bad request).
        if ((field.render === 'text' || field.render === 'textarea')
            && value === '') {
            showStatus(field.label + ': value cannot be empty', 'error');
            return;
        }
        if (field.render === 'numeric' && value !== '' && isNaN(parseFloat(value))) {
            showStatus(field.label + ': not a number', 'error');
            return;
        }
        // For 'date', empty IS valid (clears the date column to NULL).

        if (selectedIds.length === 0) {
            showStatus('No tickets selected', 'error');
            return;
        }

        $btn.prop('disabled', true);
        commitFieldUpdate(field.update_field, value, selectedIds.slice(), {
            afterCommit: function () { $btn.prop('disabled', false); },
            statusTarget: '#quickassign-toolbar .qa-status-msg'
        });
    }

    // ---------------------------------------------------------------------
    // Shared commit path: single AJAX call, single source of truth for the
    // success-flash + refresh choreography.
    //
    // Args:
    //   updateField  string   field name expected by ajax/update.php
    //   value        any      new value (server validates per-shape)
    //   ticketIds    int[]    tickets to update (caller's snapshot)
    //   options      object   optional callbacks/customization:
    //     afterCommit  fn      runs once the request settles
    //     statusTarget string  jQuery selector for showStatus output;
    //                          defaults to the toolbar's status row
    //     onSuccess    fn(updatedIds)  called before the refresh fires;
    //                          inline edit uses this to close the editor
    //     onFailure    fn(errMsg)      called on a hard failure (no
    //                          successes); inline edit uses this to keep
    //                          the editor open with an error message
    // ---------------------------------------------------------------------
    function commitFieldUpdate(updateField, value, ticketIds, options) {
        options = options || {};
        var statusTarget = options.statusTarget || null;
        ticketIds = (ticketIds || []).slice(); // snapshot for this request

        $.ajax({
            url: rootDoc + '/plugins/quickassign/ajax/update.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ticket_ids: ticketIds,
                field: updateField,
                value: value
            }
        })
        .done(function (response) {
            if (response && response.error) {
                showStatusAt(statusTarget, 'Error: ' + response.error, 'error');
                if (typeof options.onFailure === 'function') options.onFailure(response.error);
                return;
            }
            var ok = (response && response.success) ? response.success.length : 0;
            var ko = (response && response.failed)  ? response.failed.length  : 0;
            // Stay quiet on success — the row flash + refreshed values are
            // confirmation enough. Only surface a message if something
            // actually went wrong.
            if (ko > 0) {
                var firstErr = '';
                if (response.errors) {
                    for (var k in response.errors) {
                        if (Object.prototype.hasOwnProperty.call(response.errors, k)) {
                            firstErr = response.errors[k];
                            break;
                        }
                    }
                }
                var msg = 'Updated ' + ok + ', ' + ko + ' failed'
                        + (firstErr ? ' (' + firstErr + ')' : '');
                showStatusAt(statusTarget, msg, 'warning');
                if (ok === 0 && typeof options.onFailure === 'function') {
                    options.onFailure(firstErr || 'update failed');
                }
            }

            var updatedIds = (response && response.success) ? response.success : [];
            if (typeof options.onSuccess === 'function' && updatedIds.length > 0) {
                options.onSuccess(updatedIds);
            }

            // Refresh the table so the new field values appear immediately,
            // then flash the affected rows once they re-render with their
            // new values. Selection survives the refresh: selectedIds is
            // preserved in JS state, and the MutationObserver re-ticks the
            // boxes (see restoreSelectionToDom) when the new rows appear.
            refreshTicketList(function () {
                flashUpdatedRows(updatedIds);
            });
        })
        .fail(function (xhr) {
            var msg = xhr.statusText || 'error';
            try {
                var body = JSON.parse(xhr.responseText || '{}');
                if (body.error) {
                    msg = body.error;
                }
            } catch (e) { /* ignore */ }
            console.error('[quickassign] update failed:', xhr.status, xhr.responseText);
            showStatusAt(statusTarget, 'Request failed: ' + msg, 'error');
            if (typeof options.onFailure === 'function') options.onFailure(msg);
        })
        .always(function () {
            if (typeof options.afterCommit === 'function') options.afterCommit();
        });
    }

    // showStatus that picks the right target. The toolbar has its own
    // status row; the inline editor has its own per-cell error placement
    // (handled separately — this function just routes to the toolbar's
    // status row for legacy callers and is a no-op when no target is set).
    function showStatusAt(targetSelector, msg, type) {
        if (!targetSelector) {
            // Fall back to the toolbar status row (legacy behavior).
            showStatus(msg, type);
            return;
        }
        var $msg = $(targetSelector);
        if (!$msg.length) return;
        $msg.removeClass('qa-info qa-success qa-error qa-warning')
            .addClass('qa-' + type)
            .text(msg)
            .show();
        if (type === 'success' || type === 'info') {
            setTimeout(function () { $msg.fadeOut(); }, 3000);
        }
    }

    function showStatus(msg, type) {
        var $msg = $('#quickassign-toolbar .qa-status-msg');
        $msg.removeClass('qa-info qa-success qa-error qa-warning')
            .addClass('qa-' + type)
            .text(msg)
            .show();
        if (type === 'success' || type === 'info') {
            setTimeout(function () { $msg.fadeOut(); }, 3000);
        }
    }

    function clearSelection() {
        // Wipe DOM checkboxes and our cache, then refresh the toolbar.
        findTicketCheckboxes(true).prop('checked', false);
        $('input[type="checkbox"][data-glpi-massiveaction-master], input.massive_action_checkbox_all')
            .prop('checked', false);
        selectedIds = [];
        updateToolbar();
    }

    // =====================================================================
    // INLINE EDIT
    //
    // Click any editable cell → cell becomes an inline editor of the
    // matching widget shape. Click any immutable cell → original behavior
    // (the existing <a> in the cell opens the ticket).
    //
    // The editor is the same shape as the toolbar widget for the same
    // field, built via renderEditorMarkup(), and committed via
    // commitFieldUpdate() with a one-element ticket-ids array.
    // =====================================================================

    // The TD that currently hosts an open editor, or null. We allow only
    // one open editor at a time; clicking another editable cell closes
    // (cancels) any in-progress edit first.
    var $activeEditCell = null;
    // Cached map: TH-element → resolved field key (or 'IMMUTABLE' / null).
    // Resolved per click on first miss; kept across clicks because the
    // table headers don't change between refreshes (the search query and
    // column set is the same — the *rows* change).
    var headerKeyCache = null;

    function setupInlineEdit() {
        // v1.4.1: bind ALL inline-edit handlers to the document, not
        // to the current .search-card element. GLPI 11's auto-refresh
        // can replace .search-card wholesale (see setupRefreshObserver
        // commentary), and any handlers bound to the old element are
        // lost. Document-delegated handlers survive any number of
        // refreshes since `document` is permanent.
        //
        // Selectors are scoped (".search-card td") so we still only act
        // on cells inside the search results, not random table cells
        // elsewhere on the page.
        var $card = $('.search-card').first();
        if (!$card.length) return;

        // Decorate every editable TD with a CSS class so the cursor +
        // hover background show up immediately (no per-click resolution
        // required). Also annotates the cells with the resolved field
        // key as a data attribute so the click handler can skip the
        // header lookup. Re-runs on every refresh via afterEachRefresh.
        decorateEditableCells();

        // Idempotency: tear down any prior bindings before re-binding.
        // setupInlineEdit() is only called once, but the .off() makes
        // hot-reload during development safe and protects against any
        // future call-site that might re-invoke it.
        $(document).off('.qaInlineEdit');

        $(document).on('click.qaInlineEdit', '.search-card td', onCellClick);
        $(document).on('keydown.qaInlineEdit',
            '.search-card .qa-inline-editor .qa-input', onEditorKeydown);
        $(document).on('click.qaInlineEdit',
            '.search-card .qa-inline-editor .qa-confirm', onEditorConfirmClick);
        $(document).on('click.qaInlineEdit',
            '.search-card .qa-inline-editor .qa-cancel', onEditorCancelClick);
        $(document).on('change.qaInlineEdit',
            '.search-card .qa-inline-editor .qa-select', onEditorSelectChange);

        // Re-decorate after every refresh. (The MutationObserver path
        // fires restoreSelectionToDom; afterEachRefresh hooks into the
        // same path so this runs at roughly the same time.)
        afterEachRefresh(function () {
            $activeEditCell = null;
            headerKeyCache  = null;
            decorateEditableCells();
            qaLog('decorated', $('.search-card td.qa-cell-editable').length,
                  'editable cells after refresh');
        });

        // Clicking outside the editor cancels (only for the
        // confirm-button widgets — Select2-driven editors should not
        // self-cancel when the dropdown panel takes focus).
        $(document).off('mousedown.qaInlineEditOutside')
            .on('mousedown.qaInlineEditOutside', function (e) {
                if (!$activeEditCell) return;
                var $target = $(e.target);
                if ($target.closest('.qa-inline-editor').length) return;
                if ($target.closest('.select2-container').length) return;
                if ($target.closest('.select2-dropdown').length) return;
                if ($target.closest('td.qa-cell-editable').length) return;
                cancelInlineEdit($activeEditCell);
            });

        // Esc anywhere in the editor cancels.
        $(document).on('keydown.qaInlineEdit',
            '.search-card .qa-inline-editor', function (e) {
                if (e.key === 'Escape' || e.keyCode === 27) {
                    e.preventDefault();
                    if ($activeEditCell) cancelInlineEdit($activeEditCell);
                }
            });

        qaLog('setupInlineEdit done. Initial editable cells:',
              $('.search-card td.qa-cell-editable').length);
    }

    // ---------------------------------------------------------------------
    // Decorate each cell that maps to an editable field with a marker
    // class + data attribute. Cheap to re-run on refresh.
    // ---------------------------------------------------------------------
    function decorateEditableCells() {
        var $rows = $('.search-card tr[data-itemtype="Ticket"]');
        if (!$rows.length) {
            // Some GLPI 11 themes drop the data-itemtype attribute on the
            // body rows. Fall back to TR rows that contain a recognizable
            // ticket checkbox.
            $rows = $('.search-card tr').filter(function () {
                return $(this).find('input[name^="item[Ticket]"]').length > 0;
            });
        }

        $rows.each(function () {
            var $tr  = $(this);
            var $tds = $tr.children('td');
            $tds.each(function (idx) {
                var $td = $(this);
                // Skip checkbox cells.
                if ($td.find('input[type="checkbox"][name^="item[Ticket]"]').length) return;
                var key = resolveFieldKeyForCell($td, idx);
                if (key) {
                    $td.addClass('qa-cell-editable')
                       .attr('data-qa-field-key', key);
                } else {
                    $td.removeClass('qa-cell-editable')
                       .removeAttr('data-qa-field-key');
                }
            });
        });
    }

    // ---------------------------------------------------------------------
    // Decide whether a TD is editable, and if so, which catalog field key.
    // Returns the field key string, or null if the cell is immutable / not
    // mapped / not in the active toolbar config.
    //
    // colIdx is the TD's index within its row (0-based, including the
    // checkbox cell). We use the matching <th> to look up search-opt num
    // and header text.
    // ---------------------------------------------------------------------
    function resolveFieldKeyForCell($td, colIdx) {
        var $tr   = $td.closest('tr');
        var $head = $tr.closest('table').find('thead tr').first();
        if (!$head.length) return null;
        var $th   = $head.children('th').eq(colIdx);
        if (!$th.length) return null;

        // Try the resolved-cache first.
        var thEl = $th.get(0);
        if (headerKeyCache && headerKeyCache.has(thEl)) {
            var cached = headerKeyCache.get(thEl);
            return cached === 'IMMUTABLE' ? null : cached;
        }
        if (!headerKeyCache) {
            headerKeyCache = (typeof Map === 'function') ? new Map() : null;
        }

        var resolved = resolveFromHeader($th, $td);
        if (headerKeyCache) headerKeyCache.set(thEl, resolved || 'IMMUTABLE');
        return resolved;
    }

    function resolveFromHeader($th, $td) {
        // 1. Search-opt number — first preference. GLPI 11 sets it on
        // either the TH or the TD depending on how the search is rendered;
        // we try both.
        var searchOpt = parseInt($th.attr('data-searchopt-num')
                              || $th.attr('data-search-opt-num')
                              || $td.attr('data-searchopt-num')
                              || $td.attr('data-search-opt-num'), 10);
        if (!isNaN(searchOpt)) {
            if (INLINE_RULES.IMMUTABLE_SEARCH_OPTS.indexOf(searchOpt) !== -1) {
                qaLog('cell immutable by search-opt', searchOpt, '·', $th.text().trim());
                return null;
            }
            var byOpt = INLINE_RULES.EDITABLE_SEARCH_OPT_MAP[searchOpt];
            if (byOpt && isFieldActive(byOpt)) return byOpt;
            // v1.4.1: previously bailed here. That meant any column
            // carrying an unrecognized search-opt-num was silently
            // non-editable even if its header text matched. Specifically
            // hits Category, where some GLPI 11 setups expose a different
            // search-opt for the category column. We now fall through to
            // header-text matching as a SAFETY NET — search-opt is still
            // first preference, but absence from EDITABLE_SEARCH_OPT_MAP
            // is no longer a hard veto.
            qaLog('search-opt', searchOpt, 'not in maps; falling back to header text "'
                  + ($th.text().trim()) + '"');
        }

        // 2. Header text fallback. Lowercased + trimmed.
        var label = String($th.text() || '').trim().toLowerCase();
        if (label === '') return null;
        // Try exact match first, then a contains-match for verbose
        // labels like "Requester - Requester".
        var key = INLINE_RULES.EDITABLE_HEADER_MAP[label];
        if (!key) {
            for (var k in INLINE_RULES.EDITABLE_HEADER_MAP) {
                if (Object.prototype.hasOwnProperty.call(INLINE_RULES.EDITABLE_HEADER_MAP, k)
                    && label.indexOf(k) !== -1) {
                    key = INLINE_RULES.EDITABLE_HEADER_MAP[k];
                    break;
                }
            }
        }
        if (key && isFieldActive(key)) {
            qaLog('cell editable via header-text "' + label + '" →', key);
            return key;
        }
        return null;
    }

    function isFieldActive(key) {
        return !!findFieldByKey(key);
    }

    // Returns the ticket ID for a given row. Tickets are identified the
    // same way as the toolbar's getTicketIdFrom — checkbox name attr.
    function getTicketIdFromRow($tr) {
        var cb = $tr.find('input[name^="item[Ticket]"]').get(0);
        if (!cb) return NaN;
        return getTicketIdFrom(cb);
    }

    // ---------------------------------------------------------------------
    // Cell click handler. Decides between "enter edit mode" and "let the
    // existing <a> link fire (open ticket)".
    // ---------------------------------------------------------------------
    function onCellClick(e) {
        var $td = $(e.currentTarget);

        // Click inside an open editor — let the editor's own handlers
        // process it.
        if ($td.hasClass('qa-cell-editing')
            || $(e.target).closest('.qa-inline-editor').length) {
            return;
        }

        // Click on the checkbox cell — preserve normal selection behavior.
        if ($td.find('input[type="checkbox"][name^="item[Ticket]"]').length) {
            return;
        }

        var key = $td.attr('data-qa-field-key');
        if (!key) {
            // Immutable cell or unknown column: leave it alone, the link
            // (if any) inside opens the ticket as before.
            return;
        }

        var $tr      = $td.closest('tr');
        var ticketId = getTicketIdFromRow($tr);
        if (!ticketId || isNaN(ticketId)) return;

        var field = findFieldByKey(key);
        if (!field) return; // shouldn't happen — decorate filters this

        // Stop the click from following any link inside the cell (the
        // cell's <a>) and from bubbling into the row-bg-click handler.
        e.preventDefault();
        e.stopPropagation();

        // If another editor is open, cancel it first.
        if ($activeEditCell && !$activeEditCell.is($td)) {
            cancelInlineEdit($activeEditCell);
        }

        enterEditMode($td, field, ticketId);
    }

    function enterEditMode($td, field, ticketId) {
        var idSuffix = sanitizeIdSuffix(field.key) + '-' + ticketId;
        var markup   = ''
            + '<div class="qa-inline-editor" '
            +      'data-key="' + escapeAttr(field.key) + '" '
            +      'data-ticket-id="' + ticketId + '">'
            + renderEditorMarkup(field, {
                inputId:    'qa-inline-input-'  + idSuffix,
                selectId:   'qa-inline-select-' + idSuffix,
                showCancel: true
              })
            + '</div>';

        $td.data('qa-original-html', $td.html());
        $td.addClass('qa-cell-editing').empty().append(markup);
        $activeEditCell = $td;

        // Init Select2 / focus the input.
        var $editor = $td.find('.qa-inline-editor');
        if (field.render === 'static'
            || field.render === 'ajax_user'
            || field.render === 'ajax_dropdown'
            || field.render === 'ajax_ticket') {
            var $sel = $editor.find('.qa-select');
            initSelect2For($sel, field, {
                context:    'inline',
                excludeIds: [ticketId]
            });
            // Open the dropdown so the user goes straight to picking.
            setTimeout(function () { $sel.select2('open'); }, 0);
        } else if (field.render === 'textarea' && field.prefill_endpoint) {
            // Pre-fill flow (currently used by Description): block the
            // textarea + confirm button briefly while we fetch the
            // current value from the server, then drop it in and put
            // the cursor at the end. The user sees the existing text
            // and can edit it rather than retyping from scratch.
            //
            // Why server-side: the cell's visible text is usually a
            // truncated, HTML-stripped preview. Going to the server
            // for the full column value is the only way to avoid
            // silently truncating the user's data on commit.
            var $ta  = $editor.find('.qa-input');
            var $btn = $editor.find('.qa-confirm');
            $ta.prop('disabled', true).val('Loading…');
            $btn.prop('disabled', true);
            qaLog('prefill: fetching content for ticket', ticketId);
            $.ajax({
                url:      field.prefill_endpoint,
                type:     'GET',
                dataType: 'json',
                cache:    false,
                data:     { ticket_id: ticketId }
            })
            .done(function (data) {
                var text = (data && typeof data.content_text === 'string')
                         ? data.content_text : '';
                $ta.prop('disabled', false).val(text);
                $btn.prop('disabled', false);
                // Move cursor to end and focus. Selecting all would
                // make the next keystroke wipe the text — undesirable
                // for an edit-existing flow.
                var el = $ta.get(0);
                if (el) {
                    try {
                        el.focus();
                        var n = el.value.length;
                        el.setSelectionRange(n, n);
                    } catch (e) { /* ignore */ }
                }
            })
            .fail(function (xhr) {
                console.warn('[quickassign] prefill failed:',
                             xhr.status, xhr.statusText);
                // Fall back to empty + enabled. The user can still
                // type a new value — they just lose the convenience.
                $ta.prop('disabled', false).val('').attr('placeholder',
                    'Could not load current value — type new description');
                $btn.prop('disabled', false);
                $ta.focus();
            });
        } else {
            // Plain text/numeric/date inline edit: empty input, focus,
            // select-all so the user can just start typing.
            $editor.find('.qa-input').focus().select();
        }
    }

    function cancelInlineEdit($td) {
        if (!$td || !$td.length) return;
        var original = $td.data('qa-original-html');
        if (typeof original === 'string') {
            $td.html(original);
        } else {
            $td.empty();
        }
        $td.removeData('qa-original-html');
        $td.removeClass('qa-cell-editing');
        if ($activeEditCell && $activeEditCell.is($td)) {
            $activeEditCell = null;
        }
    }

    // ---------------------------------------------------------------------
    // Editor event handlers — confirm / cancel / Enter / select-change.
    // ---------------------------------------------------------------------
    function onEditorKeydown(e) {
        if (!isCommitKey(e, e.target)) return;
        e.preventDefault();
        // Trigger the matching confirm button in the same editor.
        $(e.target).closest('.qa-inline-editor').find('.qa-confirm').trigger('click');
    }

    function onEditorConfirmClick(e) {
        // Don't let this click bubble up to the TD click handler, which
        // would otherwise see the (now possibly torn-down) editor and
        // try to re-enter edit mode.
        e.stopPropagation();

        var $btn    = $(e.currentTarget);
        var $editor = $btn.closest('.qa-inline-editor');
        var $td     = $editor.closest('td');
        var key     = $editor.attr('data-key');
        var ticketId = parseInt($editor.attr('data-ticket-id'), 10);
        var field   = findFieldByKey(key);
        if (!field || !ticketId) return;

        var $input = $editor.find('.qa-input');
        if (!$input.length) return;

        var raw   = $input.val();
        var value = (raw == null) ? '' : String(raw).trim();

        // Per-shape minimum validation (mirrors handleToolbarConfirmClick).
        if ((field.render === 'text' || field.render === 'textarea')
            && value === '') {
            $input.focus();
            // Lightweight visual nudge — the styling already covers focus.
            $input.css('border-color', '#c92a2a');
            return;
        }
        if (field.render === 'numeric' && value !== '' && isNaN(parseFloat(value))) {
            $input.focus();
            $input.css('border-color', '#c92a2a');
            return;
        }

        $btn.prop('disabled', true);
        commitFieldUpdate(field.update_field, value, [ticketId], {
            // No statusTarget — refreshTicketList will replace the row, so
            // we don't need a status row inside the editor for success.
            onFailure: function (msg) {
                // Keep the editor open and let the user retry. Surface
                // the failure as a tooltip on the input.
                $btn.prop('disabled', false);
                $input.css('border-color', '#c92a2a');
                $input.attr('title', msg);
            },
            afterCommit: function () {
                // The list refresh that follows a success will tear down
                // this editor by replacing the cell. Nothing to do here
                // for the success case. For failures, the editor stays
                // open thanks to onFailure leaving $activeEditCell set.
            }
        });
    }

    function onEditorCancelClick(e) {
        e.stopPropagation();
        var $td = $(e.currentTarget).closest('td');
        cancelInlineEdit($td);
    }

    function onEditorSelectChange(e) {
        var $sel    = $(e.currentTarget);
        var $editor = $sel.closest('.qa-inline-editor');
        var $td     = $editor.closest('td');
        var key     = $editor.attr('data-key');
        var ticketId = parseInt($editor.attr('data-ticket-id'), 10);
        var field   = findFieldByKey(key);
        var value   = $sel.val();
        if (!field || !ticketId || !value) return;

        commitFieldUpdate(field.update_field, value, [ticketId], {
            onFailure: function (msg) {
                // Reset the select so the user can retry. The status
                // bubble would be too noisy here — log and keep the
                // editor open with a red border on the select container.
                console.warn('[quickassign] inline edit failed:', msg);
                $editor.css('border-color', '#c92a2a');
            }
        });
    }

    // ---------------------------------------------------------------------
    // Hook a callback to fire shortly after each list refresh. Reuses the
    // same MutationObserver path the toolbar uses for selection restore;
    // we just install a tiny piggyback observer on the same target.
    // ---------------------------------------------------------------------
    var refreshCallbacks = [];
    function afterEachRefresh(fn) {
        refreshCallbacks.push(fn);
        // Lazy-install the observer the first time someone subscribes.
        if (refreshCallbacks.length === 1) {
            installRefreshCallbackObserver();
        }
    }
    function installRefreshCallbackObserver() {
        if (typeof MutationObserver === 'undefined') return;
        // v1.4.1: same stable-ancestor logic as setupRefreshObserver.
        // Was watching .search-card directly, which gets detached on
        // GLPI 11 auto-refresh and never fires again.
        var target = document.querySelector('main')
                  || document.querySelector('#page')
                  || document.body;
        if (!target) return;
        var debounceTimer = null;

        function affectsSearchCard(mutation) {
            if ($(mutation.target).closest('.search-card').length) return true;
            function has(nodes) {
                for (var i = 0; i < nodes.length; i++) {
                    var n = nodes[i];
                    if (n && n.nodeType === 1) {
                        if (n.matches && n.matches('.search-card')) return true;
                        if (n.querySelector && n.querySelector('.search-card')) return true;
                    }
                }
                return false;
            }
            return has(mutation.addedNodes) || has(mutation.removedNodes);
        }

        var obs = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                if (m.addedNodes.length === 0 && m.removedNodes.length === 0) continue;
                if (!affectsSearchCard(m)) continue;
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function () {
                    qaLog('refresh callbacks firing (', refreshCallbacks.length, ' subscribers)');
                    for (var j = 0; j < refreshCallbacks.length; j++) {
                        try { refreshCallbacks[j](); }
                        catch (e) { console.error('[quickassign] refresh callback failed:', e); }
                    }
                }, 160); // slightly after restoreSelectionToDom (150ms)
                return;
            }
        });
        obs.observe(target, { childList: true, subtree: true });
    }

    // =====================================================================
    // OPTIONAL: row-background click → open ticket
    //
    // Gated by the `row_background_opens_ticket` setting. When on, a click
    // anywhere in a ticket row that isn't an editable cell, an editor, an
    // input/button/link, or the checkbox column opens the ticket.
    // =====================================================================
    function setupRowBackgroundClick() {
        // v1.4.1: same orphan-handler concern as setupInlineEdit. Bind
        // the click to document with a scoped selector so it survives
        // .search-card replacement on auto-refresh.
        var $card = $('.search-card').first();
        if (!$card.length) return;

        // Visual: cursor:pointer on the row. Re-applies after every refresh.
        function decorateRows() {
            $('.search-card tr[data-itemtype="Ticket"]').addClass('qa-row-clickable-bg');
            // Same fallback as decorateEditableCells.
            $('.search-card tr').filter(function () {
                return $(this).find('input[name^="item[Ticket]"]').length > 0;
            }).addClass('qa-row-clickable-bg');
        }
        decorateRows();
        afterEachRefresh(decorateRows);

        $(document).off('click.qaRowBg').on('click.qaRowBg',
            '.search-card tr.qa-row-clickable-bg', function (e) {
                var $target = $(e.target);

                // Skip interactive elements.
                if ($target.is('input, button, select, a, label')) return;
                if ($target.closest('input, button, select, a, label,'
                                    + ' .qa-inline-editor, .select2-container,'
                                    + ' .select2-dropdown, .qa-cell-editing').length) return;

                // Skip clicks that landed on an editable cell — that handler
                // ran before us and either entered edit mode (and stopped
                // propagation) or did nothing because the click was on the
                // existing link. Either way, don't navigate from here.
                if ($target.closest('td.qa-cell-editable').length) return;

                var ticketId = getTicketIdFromRow($(this));
                if (!ticketId || isNaN(ticketId)) return;
                window.location.href = rootDoc + '/front/ticket.form.php?id=' + ticketId;
        });
    }

    // =====================================================================
    // OPTIONAL: empty-cell click-through (inline edit OFF mode)
    //
    // Wired only when pluginSettings.enable_inline_edit is false. The
    // problem this solves: when the admin turns inline edit off, cells
    // that WOULD have been editable revert to GLPI's stock rendering.
    // Cells with content keep their <a href="ticket.form.php?id=N">
    // wrapper and clicking them opens the ticket as normal. But cells
    // with no value (an unassigned technician column, an empty category,
    // etc.) have no link inside, so a click on them does literally
    // nothing — they're dead pixels.
    //
    // This handler tags those empty cells with `qa-cell-empty-clickthrough`
    // (cursor:pointer via CSS) and intercepts the click to navigate to
    // the ticket form. Cells that ALREADY have a link are not touched —
    // the existing <a> handles navigation, and we'd double-fire otherwise.
    //
    // Scoping: we use the same resolveFieldKeyForCell() the inline-edit
    // path uses, so only columns the admin enabled in the toolbar config
    // get the treatment. Columns the plugin doesn't know about behave
    // exactly like vanilla GLPI.
    //
    // Refresh: same afterEachRefresh hook the other features use, so the
    // decoration survives GLPI 11's auto-refresh cycle.
    // =====================================================================
    function setupEmptyCellClickThrough() {
        function decorate() {
            var $rows = $('.search-card tr[data-itemtype="Ticket"]');
            if (!$rows.length) {
                // Same fallback as decorateEditableCells — some GLPI 11
                // themes drop the data-itemtype attribute on body rows.
                $rows = $('.search-card tr').filter(function () {
                    return $(this).find('input[name^="item[Ticket]"]').length > 0;
                });
            }
            $rows.each(function () {
                var $tr  = $(this);
                var $tds = $tr.children('td');
                $tds.each(function (idx) {
                    var $td = $(this);
                    // Skip checkbox cells.
                    if ($td.find('input[type="checkbox"][name^="item[Ticket]"]').length) return;

                    var key = resolveFieldKeyForCell($td, idx);
                    if (!key) {
                        // Column isn't plugin-managed — leave it as-is.
                        $td.removeClass('qa-cell-empty-clickthrough')
                           .removeAttr('data-qa-field-key');
                        return;
                    }
                    // Track the resolved key so future decorate() runs
                    // can avoid re-resolving (and so anything else
                    // inspecting cells can tell what field they map to).
                    $td.attr('data-qa-field-key', key);

                    // Empty == no <a href> inside the cell. Cells with a
                    // link are already navigable; intercepting them would
                    // be redundant. Re-checked on every refresh because a
                    // cell can transition from empty to populated (or
                    // vice versa) as tickets get edited elsewhere.
                    if ($td.find('a[href]').length === 0) {
                        $td.addClass('qa-cell-empty-clickthrough');
                    } else {
                        $td.removeClass('qa-cell-empty-clickthrough');
                    }
                });
            });
        }
        decorate();
        afterEachRefresh(decorate);

        // Document-delegated handler — same survives-refresh pattern as
        // setupInlineEdit / setupRowBackgroundClick.
        $(document).off('click.qaEmptyCellThrough').on('click.qaEmptyCellThrough',
            '.search-card td.qa-cell-empty-clickthrough', function (e) {
                var $target = $(e.target);
                // Defensive: an empty cell shouldn't have any interactive
                // children (no <a> by construction), but a stray checkbox
                // or button isn't unimaginable on customized installs.
                if ($target.is('input, button, select, a, label')) return;
                if ($target.closest('input, button, select, a, label').length) return;

                var ticketId = getTicketIdFromRow($(this).closest('tr'));
                if (!ticketId || isNaN(ticketId)) return;

                e.preventDefault();
                // stopImmediatePropagation so the row-background handler
                // (if also bound, with row_background_opens_ticket on)
                // doesn't fire a redundant second navigation. Same URL,
                // but cleaner.
                e.stopImmediatePropagation();
                window.location.href = rootDoc + '/front/ticket.form.php?id=' + ticketId;
        });

        qaLog('setupEmptyCellClickThrough done. Initial empty editable cells:',
              $('.search-card td.qa-cell-empty-clickthrough').length);
    }

})(window.jQuery);
