// assets/global_js/import_preview.js
// The review step of both upload wizards (first upload and Settings). Good rows
// are listed first. Rows with issues follow, with every field editable: a row
// stays red until it's valid, then turns yellow. Rows still red are left out, and
// the owner can drop any row.

var ImportPreview = (function () {
    var PER_PAGE = 15;
    var FIELDS   = ['date', 'product', 'sku', 'qty', 'category', 'cost', 'price'];
    var LABELS   = { date: 'Date', qty: 'Qty', sku: 'SKU', category: 'Category', cost: 'Cost', price: 'Selling price' };
    var ICON     = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    var HEADERS  = '<thead><tr><th>Row</th><th>Date</th><th>Product</th><th>SKU</th><th>Qty</th><th>Category</th><th>Cost</th><th>Selling price</th></tr></thead>';
    var READY_HEADERS = HEADERS.replace('</tr>', '<th></th></tr>');

    var rows = [], card = null, onChange = null, csvRows = 0, today = '';
    var pages = { ready: 1, issues: 1 };

    function esc(v) {
        return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function text(v) { return v === null || v === undefined ? '' : String(v); }
    function plural(n, word) { return n.toLocaleString() + ' ' + word + (n !== 1 ? 's' : ''); }
    function quoted(v) { return '"' + (v.length > 40 ? v.slice(0, 40) + '…' : v) + '"'; }

    // ── The same rules api/import.php applies ────────────────────────────────
    function validDate(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
        if (!m || +m[1] < 1900) return false;
        var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
        return d.getUTCMonth() === +m[2] - 1 && d.getUTCDate() === +m[3];
    }

    function cleanMoney(raw) {
        var s = raw.trim().replace(/^(?:₱|PHP|Php|php|\$|P(?=\s*[\d.]))\s*/, '').replace(/\s*(?:₱|PHP|Php|php|\$)\s*$/, '');
        return /^-?\d{1,3}(?:,\d{3})+(?:\.\d+)?$/.test(s) ? s.replace(/,/g, '') : s;
    }

    function validMoney(raw) {
        var s = cleanMoney(raw);
        return s === '' || (/^-?(\d+\.?\d*|\.\d+)$/.test(s) && Math.abs(Number(s)) <= 99999999.99);
    }

    function moneyValue(raw) {
        return raw.trim() === '' || !validMoney(raw) ? null : Number(cleanMoney(raw));
    }

    function quantityProblem(qty) {
        if (qty === '') return 'Quantity is blank. Type how many were sold.';
        if (/^\d+$/.test(qty) && +qty >= 1 && +qty <= 999999) return '';
        if (!/^-?\d*\.?\d+$/.test(qty)) return 'Quantity ' + quoted(qty) + ' has to be a plain number, with no commas, units or words.';
        if (+qty <= 0)     return 'Quantity ' + qty + ' has to be at least 1.';
        if (+qty > 999999) return 'Quantity ' + qty + ' is too large. The most allowed is 999,999.';
        return 'Quantity ' + qty + ' has to be a whole number.';
    }

    // An issue names the boxes to highlight along with its explanation.
    function issue(fields, text) { return { fields: fields, text: text }; }

    // Problems that stop the row from being imported.
    function blockingIssues(r) {
        var issues  = [];
        var product = r.product.trim(), date = r.date.trim();
        var longCategory = r.category.split(',').filter(function (c) { return c.trim().length > 50; })[0];

        if (product === '')       issues.push(issue(['product'], 'Product is blank. Type the product name.'));
        else if (product.length > 100) issues.push(issue(['product'], 'Product name is ' + product.length + ' characters long. The most allowed is 100.'));

        if (date === '')          issues.push(issue(['date'], 'Date is blank. Pick the sale date.'));
        else if (!validDate(date)) issues.push(issue(['date'], 'Date ' + quoted(date) + ' can\'t be read as a real date. Pick the sale date.'));
        else if (date > today)    issues.push(issue(['date'], 'Date ' + date + ' is in the future. A sale can\'t be dated after today. Pick the actual sale date.'));

        var quantity = quantityProblem(r.qty.trim());
        if (quantity) issues.push(issue(['qty'], quantity));

        if (r.sku.trim().length > 100) issues.push(issue(['sku'], 'SKU is ' + r.sku.trim().length + ' characters long. The most allowed is 100.'));
        if (longCategory) issues.push(issue(['category'], 'Category ' + quoted(longCategory.trim()) + ' is too long. Each category can have up to 50 characters.'));
        if (!validMoney(r.cost))  issues.push(issue(['cost'], 'Cost ' + quoted(r.cost.trim()) + ' has to be a number.'));
        if (!validMoney(r.price)) issues.push(issue(['price'], 'Selling price ' + quoted(r.price.trim()) + ' has to be a number.'));
        return issues;
    }

    // Values that can be stored but may be wrong. Each one clears only when the
    // value behind it is corrected, so editing another field doesn't count.
    function reviewIssues(r) {
        var issues = r.warnings.filter(function (w) {
            return !w.recheck && r[w.field] === r.original[w.field];
        }).map(function (w) {
            return issue([w.field], (LABELS[w.field] || w.field) + ' ' + quoted(w.original) + ': ' + w.reason + '.');
        });

        var cost = moneyValue(r.cost), price = moneyValue(r.price);
        if ((cost !== null && cost <= 0) || (price !== null && price <= 0)) {
            var notPositive = [];
            if (cost !== null && cost <= 0)   notPositive.push('cost');
            if (price !== null && price <= 0) notPositive.push('price');
            issues.push(issue(notPositive, 'Cost and selling price should be above 0, or no order can be suggested.'));
        } else if (cost !== null && price !== null && cost >= price) {
            issues.push(issue(['cost', 'price'], 'Cost (' + cost + ') is not below the selling price (' + price + '), so no order can be suggested.'));
        }
        return issues;
    }

    // ── Row state ────────────────────────────────────────────────────────────
    function toRow(r) {
        var invalid = r.status === 'invalid';
        var row = {
            rowNum:   r.rowNum,
            merged:   r.agg_count > 1 ? r.agg_count - 1 : 0,
            date:     text(r.date),
            product:  text(r.product),
            sku:      text(r.sku),
            qty:      invalid ? text(r.raw_qty) : text(r.qty),
            category: text(r.category),
            cost:     text(r.cost),
            price:    text(r.price),
            wrongColumns: !!r.wrong_columns,
            warnings: r.warnings || [],
            kept:     false,
            dropped:  false,
        };
        row.section  = invalid || row.warnings.length ? 'issues' : 'ready';
        row.original = {};
        FIELDS.forEach(function (f) { row.original[f] = row[f]; });
        return row;
    }

    function isChanged(r) {
        return FIELDS.some(function (f) { return r[f] !== r.original[f]; });
    }

    function classify(r) {
        r.canKeep = false;
        r.issues  = [];

        if (r.dropped) {
            r.state   = 'dropped';
            r.message = 'Dropped. This row will not be imported.';
            return;
        }

        if (r.section === 'ready') {
            r.state = 'ready';
            return;
        }

        var blocking = blockingIssues(r);
        var review   = reviewIssues(r);
        var confirm  = r.wrongColumns && !r.kept;

        r.issues = blocking.concat(
            r.wrongColumns ? [issue([], 'This row had the wrong number of cells, so values may be in the wrong boxes. Check them, then press Keep this row.')] : [],
            review
        );

        if (blocking.length || confirm) {
            r.state   = 'invalid';
            r.canKeep = !blocking.length;
            r.message = 'Fix ' + (r.issues.length === 1 ? 'this' : 'all of these') + ' or the row will be left out.';
        } else if (r.kept) {
            r.state   = 'kept';
            r.message = 'Kept as shown.';
        } else if (review.length) {
            r.state   = 'review';
            r.canKeep = true;
            r.message = 'It will import as shown unless you fix ' + (review.length === 1 ? 'this' : 'these') + '. If it is correct, press Keep this row.';
        } else {
            r.state   = 'fixed';
            r.message = 'Fixed.';
        }
    }

    // A fixed row can land on the same product and date as another row. Those
    // are added together on import, like repeat sales in the file, so say so.
    function noteDuplicates() {
        var first = {};
        ['ready', 'issues'].forEach(function (name) {
            section(name).forEach(function (r) {
                r.addsTo = null;
                if (r.state === 'invalid' || r.state === 'dropped') return;
                var key = r.product.trim().toLowerCase() + '|' + r.date.trim();
                if (first[key] === undefined) first[key] = r;
                else r.addsTo = first[key].rowNum;
            });
        });
    }

    // A product has one current price, taken from its newest row. If a correction
    // is on an older row, say which value the product will keep.
    function notePriceOverrides() {
        rows.forEach(function (r) { r.priceNotes = []; });
        var counted = rows.filter(function (r) { return r.state !== 'invalid' && r.state !== 'dropped'; });

        ['cost', 'price'].forEach(function (field) {
            var newest = {};
            counted.forEach(function (r) {
                if (r[field].trim() === '' || !validMoney(r[field])) return;
                var key = r.product.trim().toLowerCase();
                if (!newest[key] || r.date.trim() > newest[key].date.trim()) newest[key] = r;
            });
            counted.forEach(function (r) {
                if (r.section !== 'issues' || r[field] === r.original[field] || r[field].trim() === '') return;
                var winner = newest[r.product.trim().toLowerCase()];
                if (winner && winner !== r && moneyValue(winner[field]) !== moneyValue(r[field])) {
                    r.priceNotes.push('The product will keep ' + LABELS[field] + ' ' + winner[field].trim() + ' from its newer row ' + winner.rowNum + ', because each product has one current price.');
                }
            });
        });
    }

    function refreshAll() {
        rows.forEach(classify);
        noteDuplicates();
        notePriceOverrides();
    }

    function counts() {
        var c = { ready: 0, fixed: 0, kept: 0, review: 0, invalid: 0, dropped: 0 };
        rows.forEach(function (r) { c[r.state]++; });
        c.importable = c.ready + c.fixed + c.kept + c.review;
        return c;
    }

    // ── Drawing ──────────────────────────────────────────────────────────────
    function section(name) {
        return rows.filter(function (r) { return r.section === name; });
    }

    function rowCell(r) {
        return esc(r.rowNum) + (r.merged ? ' <span class="preview-agg">+' + r.merged + '</span>' : '');
    }

    function money(v) { return v === '' ? '<span class="preview-dash">—</span>' : esc(v); }

    function readyRow(r) {
        var index = rows.indexOf(r);
        var button = r.dropped
            ? '<button type="button" class="preview-row-btn" data-action="restore" data-row="' + index + '">Bring back</button>'
            : '<button type="button" class="preview-row-btn" data-action="drop" data-row="' + index + '">Drop</button>';
        return '<tr class="preview-row ' + (r.dropped ? 'preview-row-dropped' : 'preview-row-new') + '">' +
            '<td class="preview-row-num">' + rowCell(r) + '</td>' +
            '<td>' + esc(r.date) + '</td>' +
            '<td class="preview-product" title="' + esc(r.product) + '">' + esc(r.product) + '</td>' +
            '<td>' + money(r.sku) + '</td>' +
            '<td>' + esc(r.qty) + '</td>' +
            '<td>' + money(r.category) + '</td>' +
            '<td>' + money(r.cost) + '</td>' +
            '<td>' + money(r.price) + '</td>' +
            '<td class="preview-actions">' + button + '</td></tr>';
    }

    function input(r, field, type) {
        var value = field === 'date' && !validDate(r.date) ? '' : r[field];
        return '<input class="preview-input preview-input-' + field + '" type="' + type + '" data-field="' + field +
               '" value="' + esc(value) + '"' + (field === 'date' ? ' max="' + today + '"' : '') +
               ' aria-label="' + field + ' for row ' + r.rowNum + '">';
    }

    function issueBody(r) {
        return '<tbody data-row="' + rows.indexOf(r) + '">' +
            '<tr class="preview-issue-fields">' +
            '<td class="preview-row-num">' + rowCell(r) + '</td>' +
            '<td>' + input(r, 'date', 'date') + '</td>' +
            '<td>' + input(r, 'product', 'text') + '</td>' +
            '<td>' + input(r, 'sku', 'text') + '</td>' +
            '<td>' + input(r, 'qty', 'text') + '</td>' +
            '<td>' + input(r, 'category', 'text') + '</td>' +
            '<td>' + input(r, 'cost', 'text') + '</td>' +
            '<td>' + input(r, 'price', 'text') + '</td></tr>' +
            '<tr class="preview-issue-note"><td colspan="8"></td></tr></tbody>';
    }

    // Updates a row's color and message in place, so typing keeps its focus.
    function paintIssue(body) {
        var r     = rows[+body.dataset.row];
        var label = { invalid: plural(r.issues.length, 'issue'), review: 'Needs review', fixed: 'Fixed', kept: 'Kept', dropped: 'Dropped' }[r.state];
        var color = r.state === 'fixed' || r.state === 'kept' ? 'fixed' : r.state === 'dropped' ? 'dropped' : 'invalid';
        var msg   = r.message + (r.state !== 'invalid' && r.state !== 'dropped' && r.addsTo ? ' It will be added to row ' + r.addsTo + ', which has the same product and date.' : '');
        if (r.state !== 'invalid' && r.state !== 'dropped' && r.priceNotes.length) msg += ' ' + r.priceNotes.join(' ');
        var list  = r.issues.map(function (i) { return '<li>' + esc(i.text) + '</li>'; }).join('');
        var bad   = {};
        if (r.state !== 'kept') r.issues.forEach(function (i) { i.fields.forEach(function (f) { bad[f] = true; }); });

        body.querySelectorAll('input[data-field]').forEach(function (input) {
            input.classList.toggle('preview-input-bad', !!bad[input.dataset.field]);
            input.disabled = r.dropped;
        });

        body.querySelectorAll('tr').forEach(function (tr) {
            tr.classList.remove('preview-row-invalid', 'preview-row-fixed', 'preview-row-dropped');
            tr.classList.add('preview-row-' + color);
        });
        body.querySelector('.preview-issue-note td').innerHTML =
            '<span class="preview-badge preview-badge-' + color + '">' + label + '</span>' +
            '<span class="preview-issue-message">' + esc(msg) + '</span>' +
            '<span class="preview-issue-actions">' +
            (r.canKeep ? '<button type="button" class="preview-row-btn" data-action="keep">Keep this row</button>' : '') +
            (!r.dropped && (isChanged(r) || r.kept) ? '<button type="button" class="preview-row-btn" data-action="undo">Undo</button>' : '') +
            (r.dropped
                ? '<button type="button" class="preview-row-btn" data-action="restore">Bring back</button>'
                : '<button type="button" class="preview-row-btn" data-action="drop">Drop this row</button>') +
            '</span>' +
            (list ? '<ul class="preview-issue-list">' + list + '</ul>' : '');
    }

    function paintVisibleIssues() {
        card.querySelectorAll('tbody[data-row]').forEach(paintIssue);
    }

    function summaryRow(cls, label, value) {
        return '<div class="preview-summary-row ' + cls + '"><span class="preview-summary-label">' + label +
               '</span><span class="preview-summary-value">' + value.toLocaleString() + '</span></div>';
    }

    function summaryHtml() {
        var c = counts();
        return summaryRow('preview-summary-total', 'Total rows in file', csvRows) +
               summaryRow('preview-summary-new', 'Ready to import', c.ready) +
               (c.fixed   ? summaryRow('preview-summary-edited', 'Fixed by you', c.fixed) : '') +
               (c.kept    ? summaryRow('preview-summary-edited', 'Kept as shown', c.kept) : '') +
               (c.review  ? summaryRow('preview-summary-edited', 'Need review (will import as shown)', c.review) : '') +
               (c.dropped ? summaryRow('preview-summary-dropped', 'Dropped by you', c.dropped) : '') +
               (c.invalid ? summaryRow('preview-summary-invalid', 'Still have issues (will be left out)', c.invalid) : '');
    }

    function refreshTotals() {
        card.querySelector('.preview-summary').innerHTML = summaryHtml();
        card.querySelector('[data-count="issues"]').textContent = section('issues').length.toLocaleString();
        if (onChange) onChange(counts());
    }

    function pager(name, total) {
        var pagesTotal = Math.max(1, Math.ceil(total / PER_PAGE));
        var page = pages[name];
        if (pagesTotal === 1) return '';
        var btn = function (to, label, off) {
            return '<button type="button" class="preview-page-btn" data-section="' + name + '" data-page="' + to + '"' + (off ? ' disabled' : '') + '>' + label + '</button>';
        };
        return '<div class="preview-pagination-info">' + plural(total, 'row') + '</div>' +
               '<div class="preview-pagination-controls">' +
               btn(1, '« First', page === 1) + btn(page - 1, '← Prev', page === 1) +
               '<span class="preview-pagination-page">Page ' + page + ' of ' + pagesTotal + '</span>' +
               btn(page + 1, 'Next →', page === pagesTotal) + btn(pagesTotal, 'Last »', page === pagesTotal) +
               '</div>';
    }

    function slice(list, name) {
        var start = (pages[name] - 1) * PER_PAGE;
        return list.slice(start, start + PER_PAGE);
    }

    function drawSection(name) {
        var list  = section(name);
        var table = card.querySelector('[data-table="' + name + '"]');
        var old   = table.querySelectorAll('tbody');
        old.forEach(function (b) { b.remove(); });

        if (name === 'ready') {
            table.insertAdjacentHTML('beforeend', '<tbody>' + (slice(list, name).map(readyRow).join('') ||
                '<tr><td colspan="9" class="preview-empty">No rows are ready yet.</td></tr>') + '</tbody>');
        } else {
            table.insertAdjacentHTML('beforeend', slice(list, name).map(issueBody).join('') ||
                '<tbody><tr><td colspan="8" class="preview-empty">No issues found.</td></tr></tbody>');
            paintVisibleIssues();
        }
        card.querySelector('[data-pager="' + name + '"]').innerHTML = pager(name, list.length);
    }

    function replaceNotice(data) {
        var r = data.replaces;
        if (!r) return '';
        var html = '';

        if (r.rows_now === 0) {
            html += '<div class="recovery-notice">' + ICON + '<span>This becomes your store\'s dataset: <strong>' +
                    plural(r.rows_incoming, 'row') + '</strong> across <strong>' + plural(r.products_incoming, 'product') + '</strong>.</span></div>';
        } else {
            var body = 'Uploading replaces your current dataset — <strong>' + plural(r.rows_now, 'row') + '</strong> (' +
                       r.first_date_now + ' to ' + r.last_date_now + ') become <strong>' + plural(r.rows_incoming, 'row') + '</strong> (' +
                       r.first_date_new + ' to ' + r.last_date_new + '). The current data is saved to History first, ' +
                       'so you can view, download or restore it afterwards.';
            if (r.narrows_history) {
                body = '<strong>This file covers a shorter period than your stored data.</strong> ' + body +
                       ' If your export only covers recent weeks, re-export the full history before importing.';
            }
            if (r.products_leaving && r.products_leaving.length) {
                var n = r.products_leaving.length;
                body += ' <strong>' + plural(n, 'product') + '</strong> (' + r.products_leaving.slice(0, 3).map(esc).join(', ') +
                        (n > 3 ? ', +' + (n - 3) + ' more' : '') + ') ' + (n !== 1 ? 'are' : 'is') +
                        ' not in this file and will be marked inactive — kept with their settings and history, just excluded from forecasting.';
            }
            html += '<div class="recovery-notice' + (r.narrows_history ? ' recovery-notice-warn' : '') + '">' + ICON + '<span>' + body + '</span></div>';
        }

        if (data.pruned_version) {
            var p = data.pruned_version;
            html += '<div class="recovery-notice recovery-notice-warn">' + ICON + '<span>You have the maximum of <strong>' + p.max +
                    ' saved versions</strong>. Importing will permanently delete the oldest — <strong>' + esc(p.label) + '</strong> (' +
                    plural(p.total_rows, 'row') + ', saved ' + p.created_at + '). Download it from History first if you want to keep it.</span></div>';
        }
        return html;
    }

    function noticesHtml(data) {
        var html = replaceNotice(data);

        if (data.workbook_note) {
            html += '<div class="recovery-notice">' + ICON + '<span>' + esc(data.workbook_note) + '</span></div>';
        }

        if (data.recovered_count > 0) {
            html += '<div class="recovery-notice">' + ICON + '<span><strong>' + plural(data.recovered_count, 'row') +
                    '</strong> ignored the selected date format and were parsed automatically — their structure was unambiguous ' +
                    '(e.g. ISO&nbsp;YYYY-MM-DD, or a day value above&nbsp;12). If any dates look wrong in the table, adjust the ' +
                    '<strong>Date Format</strong> selector above and re-run.</span></div>';
        }

        var autoFixes = [];
        if (data.encoding_converted) autoFixes.push('converted from Windows (ANSI) encoding');
        if (data.money_reformatted > 0) {
            autoFixes.push(plural(data.money_reformatted, 'money value') + ' had currency symbols or thousands separators removed');
        }
        if (autoFixes.length) {
            html += '<div class="recovery-notice">' + ICON + '<span><strong>Cleaned automatically:</strong> ' + autoFixes.join('; ') +
                    '. Nothing was lost — no action needed.</span></div>';
        }
        return html;
    }

    // ── Editing ──────────────────────────────────────────────────────────────
    function afterEdit() {
        refreshAll();
        paintVisibleIssues();
        refreshTotals();
    }

    function onInput(e) {
        var field = e.target.dataset.field;
        var body  = e.target.closest('tbody[data-row]');
        if (!field || !body) return;
        var r = rows[+body.dataset.row];
        r[field] = e.target.value;
        r.kept   = false;   // Keep applied to the values as they were
        afterEdit();
    }

    function onClick(e) {
        var target = e.target;

        if (target.dataset.page) {
            pages[target.dataset.section] = +target.dataset.page;
            drawSection(target.dataset.section);
            return;
        }

        var action = target.dataset.action;
        if (!action) return;
        var body = target.closest('tbody[data-row]');
        var r    = rows[+(body ? body.dataset.row : target.dataset.row)];

        if (action === 'drop' || action === 'restore') {
            r.dropped = action === 'drop';
            if (!body) drawSection('ready');
        } else if (action === 'keep') {
            r.kept = true;
        } else {
            r.kept = false;
            FIELDS.forEach(function (f) { r[f] = r.original[f]; });
            body.querySelectorAll('input[data-field]').forEach(function (i) {
                var f = i.dataset.field;
                i.value = f === 'date' && !validDate(r.date) ? '' : r[f];
            });
        }
        afterEdit();
    }

    function render(containerId, data, changeHandler) {
        csvRows  = data.csv_rows || 0;
        today    = data.today;
        rows     = data.rows.map(toRow);
        onChange = changeHandler;
        pages    = { ready: 1, issues: 1 };
        refreshAll();

        var host = document.getElementById(containerId);
        var readyCount = section('ready').length;
        host.innerHTML =
            '<div class="preview-card">' +
            '<div class="preview-card-title">Review your data</div>' +
            '<div class="preview-summary"></div>' +
            noticesHtml(data) +
            '<div class="preview-section">' +
                '<div class="preview-section-title">Ready to import <span>' + readyCount.toLocaleString() + '</span></div>' +
                '<div class="preview-table-wrap"><table class="preview-table" data-table="ready">' + READY_HEADERS + '</table></div>' +
                '<div class="preview-pagination" data-pager="ready"></div>' +
            '</div>' +
            '<div class="preview-section">' +
                '<div class="preview-section-title">Rows with issues <span data-count="issues"></span></div>' +
                '<p class="preview-edit-help">Each problem is listed below its row. Correct any field here. A row turns yellow only when all of its problems are fixed, or when you keep it as shown. Rows with blocking problems are left out. You can drop any row you don\'t want.</p>' +
                '<div class="preview-table-wrap"><table class="preview-table preview-table-issues" data-table="issues">' + HEADERS + '</table></div>' +
                '<div class="preview-pagination" data-pager="issues"></div>' +
            '</div>' +
            '</div>';

        card = host.querySelector('.preview-card');
        card.addEventListener('input', onInput);
        card.addEventListener('click', onClick);
        drawSection('ready');
        drawSection('issues');
        refreshTotals();
    }

    // With no unresolved rows this just continues. Otherwise it says what will
    // happen to them and what that means for the forecast, and lets the owner go back.
    function confirmUnresolved(proceed) {
        var c = counts();
        if (!c.invalid && !c.review) { proceed(); return; }

        var parts = [];
        if (c.invalid) {
            var imported = {}, missing = {}, unnamed = 0;
            rows.forEach(function (r) {
                if (r.state !== 'invalid' && r.state !== 'dropped') imported[r.product.trim().toLowerCase()] = true;
            });
            rows.forEach(function (r) {
                if (r.state !== 'invalid') return;
                var name = r.product.trim();
                if (name === '') unnamed++;
                else if (!imported[name.toLowerCase()]) missing[name.toLowerCase()] = name;
            });
            var lost = Object.keys(missing).map(function (k) { return missing[k]; });

            var text = plural(c.invalid, 'red row') + ' will be left out and ' + (c.invalid === 1 ? 'its sales' : 'their sales') + ' will not be saved. ' +
                       'The forecast will have no sales for those days, so demand and suggested orders can come out too high or too low, ' +
                       'especially if many rows are missing or they fall on busy days like paydays and holidays.';
            if (lost.length) {
                text += ' ' + plural(lost.length, 'product') + ' (' + lost.slice(0, 3).map(function (n) { return n.length > 40 ? n.slice(0, 40) + '…' : n; }).join(', ') +
                        (lost.length > 3 ? ', +' + (lost.length - 3) + ' more' : '') + ') would have no sales left, so ' +
                        (lost.length === 1 ? 'it' : 'they') + ' will not be forecast.';
            }
            if (unnamed) {
                text += ' ' + plural(unnamed, 'row') + ' with no product name can\'t be matched to a product, so ' +
                        (unnamed === 1 ? 'that sale is' : 'those sales are') + ' lost to the forecast.';
            }
            parts.push(text);
        }
        if (c.review) {
            parts.push(plural(c.review, 'row') + ' marked Needs review will be imported as shown. Values that look wrong can skew the forecast, ' +
                       'and a missing or invalid price means no suggested order for that product.');
        }

        showConfirm({
            title:        plural(c.invalid + c.review, 'row') + ' still ' + (c.invalid + c.review === 1 ? 'has' : 'have') + ' issues',
            message:      parts.join('\n\n'),
            confirmText:  'Import anyway',
            confirmStyle: 'warning',
            onConfirm:    proceed,
        });
    }

    // The rows to send to api/import.php.
    function payload() {
        return rows.filter(function (r) { return r.state !== 'invalid' && r.state !== 'dropped'; }).map(function (r) {
            return {
                rowNum: r.rowNum, date: r.date.trim(), product: r.product.trim(), qty: r.qty.trim(),
                sku: r.sku, category: r.category, cost: r.cost, price: r.price,
                corrected: ['sku', 'cost', 'price'].filter(function (f) { return r[f] !== r.original[f]; }),
            };
        });
    }

    return { render: render, payload: payload, counts: counts, confirmUnresolved: confirmUnresolved };
}());
