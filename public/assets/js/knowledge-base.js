/* global jQuery, wpApiSettings, bootstrap */
(function ($) {
  'use strict';

  var kbSortables = [];

  function disableSortables() {
    kbSortables.forEach(function (s) {
      try { s.option('disabled', true); } catch (e) {}
    });
  }

  function enableSortables() {
    kbSortables.forEach(function (s) {
      try { s.option('disabled', false); } catch (e) {}
    });
  }

  function loadBoards() {
    return $.ajax({
      url: wpApiSettings.root + 'wp/v2/decker_board?per_page=100&_fields=id,name',
      method: 'GET',
      beforeSend: function (xhr) {
        xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
      },
    });
  }

  function loadLabels() {
    return $.ajax({
      url: wpApiSettings.root + 'wp/v2/labels?per_page=100&_fields=id,name',
      method: 'GET',
      beforeSend: function (xhr) {
        xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
      },
    });
  }

  function loadParents(boardId, excludeId) {
    return $.ajax({
      url: wpApiSettings.root + 'wp/v2/decker_kb',
      method: 'GET',
      beforeSend: function (xhr) {
        xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
      },
      data: {
        per_page: 100,
        decker_board: boardId,
        orderby: 'menu_order',
        order: 'asc',
        _fields: 'id,title'
      }
    }).then(function (articles) {
      return (articles || []).filter(function (a) { return a.id !== excludeId; });
    });
  }

  function buildInlineForm(article, boards, labels, parents) {
    const editorId = 'kb-editor-' + article.id;
    const labelList = Array.isArray(labels) ? labels : [];
    const labelOptions = labelList.map(function (l) {
      const selected = (article.labels || []).map(String).includes(String(l.id)) ? ' selected' : '';
      return '<option value="' + l.id + '"' + selected + '>' + l.name + '</option>';
    }).join('');

    return (
      '<form class="kb-inline-form" data-article-id="' + article.id + '">' +
      '  <div class="mb-2">' +
      '    <textarea id="' + editorId + '" name="content" rows="8" class="form-control"></textarea>' +
      '  </div>' +
      '  <div class="row g-2 mb-2 align-items-center">' +
      '    <div class="col-auto">' +
      '      <button type="button" class="btn btn-danger kb-inline-delete" title="' + (deckerVars?.strings?.delete || 'Eliminar') + '"><i class="ri-delete-bin-line"></i></button>' +
      '    </div>' +
      '    <div class="col-md-8">' +
      '      <select class="form-select" name="labels[]" id="kb-inline-labels-' + article.id + '" multiple>' + labelOptions + '</select>' +
      '    </div>' +
      '    <div class="col-md-3 text-end">' +
      '      <button type="button" class="btn btn-success kb-inline-save"><i class="ri-save-3-line me-1"></i> ' + (deckerVars?.strings?.save || 'Save') + '</button>' +
      '    </div>' +
      '  </div>' +
      '</form>'
    );
  }

  function initWpEditor(editorId, initial) {
    if (!window.wp || !wp.editor) return;
    const config = {
      tinymce: {
        wpautop: true,
        toolbar1: 'formatselect bold italic bullist numlist blockquote alignleft aligncenter alignright',
        menubar: false,
      },
      quicktags: true,
      mediaButtons: true,
    };
    wp.editor.initialize(editorId, config);

    // Set initial content when the editor is actually ready (robustly)
    function setWhenReady() {
      try {
        if (window.tinymce) {
          const ed = tinymce.get(editorId);
          if (ed) { ed.setContent(initial || ''); return; }
        }
      } catch (e) {}
      // Fallback to textarea if TinyMCE not present yet
      const $ta = jQuery('#' + editorId);
      if ($ta.length) { $ta.val(initial || ''); }
      // Try again shortly if TinyMCE instance not attached yet
      setTimeout(setWhenReady, 100);
    }
    setWhenReady();
  }

  function gatherFormData($form, editorId, $container) {
    const data = {
      id: Number($form.data('article-id')),
      title: ($form.closest('li.kb-item').find('.kb-title-input').first().val() || '').trim(),
      labels: $form.find('[name="labels[]"]').val() || [],
      content: ''
    };
    // Robustly retrieve editor content; fallback to initial content if needed
    try {
      if (window.wp && wp.editor && wp.editor.get(editorId)) {
        data.content = wp.editor.get(editorId).getContent();
      }
      if (!data.content && window.tinymce && tinymce.get(editorId)) {
        data.content = tinymce.get(editorId).getContent();
      }
      if (!data.content) {
        data.content = ($form.find('textarea[name="content"]').val() || '');
      }
    } catch (e) {
      data.content = ($form.find('textarea[name="content"]').val() || '');
    }
    if (!data.content && $container && $container.data('initialContent')) {
      data.content = String($container.data('initialContent'));
    }
    return data;
  }

  function reloadParentsOnBoardChange($container, articleId) {
    $container.on('change', 'select[name="board"]', function () {
      const boardId = $(this).val();
      loadParents(boardId, Number(articleId)).then(function (parents) {
        const $parent = $container.find('select[name="parent_id"]');
        const current = $parent.val();
        const opts = ['<option value="0">' + (deckerVars?.strings?.no_parent || 'Sin padre') + '</option>']
          .concat(parents.map(function (p) { return '<option value="' + p.id + '">' + (p.title?.rendered || p.title) + '</option>'; }));
        $parent.html(opts.join(''));
        if (current) { $parent.val(current); }
      });
    });
  }

  function switchTitleToInput($item) {
    const $titleWrap = (function(){
      var $w = $item.find('> .d-flex .kb-title-text').first();
      return $w.length ? $w : $item.children().find('.kb-title-text').first();
    })();
    const $a = $titleWrap.find('a.view-article-link');
    if (!$a.length) return; // already an input
    const current = $a.text();
    // Store original anchor HTML to restore later
    $titleWrap.data('orig', $titleWrap.html());
    // Wider textbox for better UX
    $titleWrap.html('<input type="text" class="form-control kb-title-input" style="font-size:16px;" value="' + $('<div>').text(current).html() + '">');
    // Focus desktop only to avoid mobile zoom
    const $input = $titleWrap.find('input.kb-title-input');
    if (!window.matchMedia('(max-width: 767px)').matches) {
      $input.focus()[0].setSelectionRange(current.length, current.length);
    }
  }

  function restoreTitleFromInput($item, newTitle) {
    const $titleWrap = (function(){
      var $w = $item.find('> .d-flex .kb-title-text').first();
      return $w.length ? $w : $item.children().find('.kb-title-text').first();
    })();
    const orig = $titleWrap.data('orig');
    if (orig) {
      $titleWrap.html(orig);
      // update anchor text and data-title/value
      const $a = $titleWrap.find('a.view-article-link');
      if ($a.length && typeof newTitle === 'string') {
        $a.text(newTitle);
        $a.attr('data-title', newTitle);
      }
      $titleWrap.removeData('orig');
    }
  }

  function openInlineEditor(articleId, $container) {
    // Fetch article details
    $.ajax({
      url: wpApiSettings.root + 'decker/v1/kb',
      method: 'GET',
      beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce); },
      data: { id: articleId }
    }).then(function (resp) {
      if (!resp || !resp.success) throw new Error('load error');
      const article = resp.article;
      return $.when(loadLabels()).then(function (labels) {
        // labels is usually the data array as first arg; ensure it's an array
        labels = Array.isArray(labels) ? labels : (labels && Array.isArray(labels[0]) ? labels[0] : []);
        const html = buildInlineForm(article, [], labels, []);
        $container.html(html);
        disableSortables();
        $container.slideDown(150);
        const editorId = 'kb-editor-' + article.id;
        initWpEditor(editorId, article.content || '');
        $container.data('initialContent', article.content || '');

        const $item = $container.closest('li.kb-item');
        switchTitleToInput($item);

        // Enhance labels select with Choices.js if present
        if (window.Choices) {
          try { new Choices('#kb-inline-labels-' + article.id, { removeItemButton: true, shouldSort: true }); } catch (e) {}
        }

        // Save
        $container.off('click.kb').on('click.kb', '.kb-inline-save', function () {
          const data = gatherFormData($container.find('form.kb-inline-form'), editorId, $container);
          $.ajax({
            url: wpApiSettings.root + 'decker/v1/kb',
            method: 'POST',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce); },
            data: data
          }).then(function (r) {
            if (!r || !r.success) throw new Error('save failed');
            // Update UI title and restore anchor
            restoreTitleFromInput($item, data.title);
            // Hide editor
            $container.slideUp(150, enableSortables);
          }).fail(function () {
            alert(deckerVars?.strings?.error || 'Error');
          });
        });

        // Inline delete
        $container.on('click.kb', '.kb-inline-delete', function () {
          const id = article.id;
          const title = article.title || '';
          if (!window.Swal) return;
          Swal.fire({
            title: deckerVars?.strings?.are_you_sure || 'Are you sure?',
            text: (deckerVars?.strings?.the_article_will_be_deleted || 'The article will be deleted') + ': "' + title + '"',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: deckerVars?.strings?.yes_delete || 'Yes, delete',
            cancelButtonText: deckerVars?.strings?.cancel || 'Cancel',
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6'
          }).then(function (result) {
            if (!result.isConfirmed) return;
            const base = (typeof wpApiSettings !== 'undefined' && wpApiSettings.versionString) ? wpApiSettings.versionString : 'wp/v2/';
            $.ajax({
              url: wpApiSettings.root + base + 'decker_kb/' + id,
              method: 'DELETE',
              beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce); },
              headers: { 'Content-Type': 'application/json' }
            }).then(function () {
              $item.remove();
              enableSortables();
            }).fail(function () {
              Swal.fire(deckerVars?.strings?.error || 'Error', deckerVars?.strings?.could_not_delete || 'Could not delete the article.', 'error');
            });
          });
        });
      });
    }).fail(function () {
      alert(deckerVars?.strings?.error || 'Error');
    });
  }

  // Inline creation removed; creation is handled with modal UI

  function initSortable() {
    if (!window.Sortable) return;
    function getContainerBoardId(el) {
      if (!el) return 0;
      var bid = el.getAttribute('data-board-id');
      if (bid) return parseInt(bid, 10) || 0;
      var li = el.closest && el.closest('li.kb-item');
      if (li && li.getAttribute('data-board-id')) return parseInt(li.getAttribute('data-board-id'), 10) || 0;
      var root = document.getElementById('kb-root');
      if (root && root.getAttribute('data-current-board-id')) return parseInt(root.getAttribute('data-current-board-id'), 10) || 0;
      return 0;
    }

    function sortableFor(el) {
      var s = new Sortable(el, {
        group: {
          name: 'kb',
          put: function(to, from, dragEl) {
            try {
              var itemBoard = parseInt(dragEl && dragEl.getAttribute('data-board-id') || '0', 10) || 0;
              var toBoard = getContainerBoardId(to && to.el);
              // Allow only if destination board equals item's board
              return itemBoard === toBoard;
            } catch (e) { return true; }
          }
        },
        animation: 150,
        fallbackOnBody: true,
        swapThreshold: 0.65,
        handle: '.kb-title-text, .kb-toggle',
        onStart: function() { document.body.classList.add('kb-dragging'); },
        onEnd: function (evt) {
            const item = evt.item;
            const movedId = parseInt(item.dataset.articleId, 10);
            const to = evt.to; const from = evt.from;
            const newParent = parseInt(to.dataset.parentId || '0', 10);
            const oldParent = parseInt(from.dataset.parentId || '0', 10);

            // Build sibling order lists (direct children only)
            const toOrder = Array.from(to.children)
              .filter(function (el) { return el.classList && el.classList.contains('kb-item'); })
              .map(function (li) { return parseInt(li.dataset.articleId, 10); });
            const fromOrder = (to === from)
              ? toOrder
              : Array.from(from.children)
                  .filter(function (el) { return el.classList && el.classList.contains('kb-item'); })
                  .map(function (li) { return parseInt(li.dataset.articleId, 10); });

            // Resolve API root and nonce with fallbacks
            var apiRoot = (window.wpApiSettings && wpApiSettings.root) ? wpApiSettings.root : (window.location.origin + '/wp-json/');
            var nonce = (window.wpApiSettings && wpApiSettings.nonce) ? wpApiSettings.nonce : (window.deckerVars && deckerVars.nonces && deckerVars.nonces.wp_rest_nonce ? deckerVars.nonces.wp_rest_nonce : null);

            $.ajax({
              url: apiRoot + 'decker/v1/kb/reorder',
              method: 'POST',
              contentType: 'application/json; charset=utf-8',
              dataType: 'json',
              processData: false,
              beforeSend: function (xhr) { if (nonce) { xhr.setRequestHeader('X-WP-Nonce', nonce); } },
              data: JSON.stringify({
                moved_id: movedId,
                new_parent_id: newParent,
                old_parent_id: oldParent,
                new_order: toOrder,
                old_order: fromOrder
              })
            }).then(function () {
              // Update dataset parent-id on moved node
              item.dataset.parentId = String(newParent);
            }).fail(function (jqXhr) {
              if (window.console) console.error('KB reorder failed', jqXhr && jqXhr.responseText ? jqXhr.responseText : jqXhr);
            });
        }
      });
      kbSortables.push(s);
      return s;
    }

    // Initialize on root lists (single or grouped by board) and all collapsible lists
    var roots = Array.from(document.querySelectorAll('#kb-root, .kb-root'));
    roots.forEach(function (ul) { sortableFor(ul); });
    document.querySelectorAll('.kb-children').forEach(function (ul) { sortableFor(ul); });

    // While dragging, expand a node's children on hover to allow nesting
    $(document).off('mouseenter.kbdrag').on('mouseenter.kbdrag', 'li.kb-item', function () {
      if (!document.body.classList.contains('kb-dragging')) return;
      var $ul = $(this).children('.kb-children');
      if ($ul.length) { try { new bootstrap.Collapse($ul[0], { toggle: false }).show(); } catch (e) {} }
    });

    $(document).off('mouseup.kbdrag touchend.kbdrag').on('mouseup.kbdrag touchend.kbdrag', function () {
      document.body.classList.remove('kb-dragging');
    });
  }

  function getItemLabels($li) {
    // Prefer parsing JSON from data-labels on the link
    const $link = $li.find('.kb-title-text a.view-article-link');
    let names = [];
    const raw = $link.attr('data-labels');
    if (raw) {
      try {
        const parsed = (typeof raw === 'string') ? JSON.parse(raw) : raw;
        if (Array.isArray(parsed)) names = parsed.map(o => (o && o.name) ? String(o.name) : '').filter(Boolean);
      } catch (e) {
        // Fallback to visible labels text
        names = $li.find('.kb-labels .badge').map(function(){ return $(this).text().trim(); }).get();
      }
    } else {
      names = $li.find('.kb-labels .badge').map(function(){ return $(this).text().trim(); }).get();
    }
    return names;
  }

  function applyFilters() {
    const q = ($('#searchInput').val() || '').toString().toLowerCase().trim();
    const selectedLabel = ($('#categoryFilter').val() || '').toString().toLowerCase().trim();
    const $items = $('li.kb-item');

    // Reset view
    $items.hide();
    if (!q && !selectedLabel) {
      $items.show();
      $('.kb-children').each(function () { new bootstrap.Collapse(this, { toggle: false }).hide(); });
      return;
    }

    $items.each(function () {
      const $li = $(this);
      const title = $li.find('.kb-title-text').text().toLowerCase();
      const content = $li.find('.kb-hidden-content').text().toLowerCase();
      const labelNames = getItemLabels($li).map(s => s.toLowerCase());
      const haystack = (title + ' ' + content + ' ' + labelNames.join(' ')).trim();

      const matchQ = !q || haystack.indexOf(q) !== -1;
      const matchLabel = !selectedLabel || labelNames.indexOf(selectedLabel) !== -1;

      if (matchQ && matchLabel) {
        $li.show();
        // Expand ancestors and show them
        $li.parents('.kb-children').each(function () {
          new bootstrap.Collapse(this, { toggle: false }).show();
          $(this).closest('li.kb-item').show();
        });
      }
    });
  }

  function initSearch() {
    const $input = $('#searchInput');
    if ($input.length) {
      $input.on('input', function () { applyFilters(); });
    }
    const $filter = $('#categoryFilter');
    if ($filter.length) {
      $filter.on('change', function () { applyFilters(); });
    }
  }

  function initCollapseIcons() {
    if (!window.bootstrap) return;

    function updateToggleIcon(collapseEl) {
      var id = collapseEl.getAttribute('id');
      if (!id) return;
      var btn = document.querySelector('.kb-toggle[data-bs-target="#' + id + '"]');
      if (!btn) return;
      var icon = btn.querySelector('i');
      if (!icon) return;
      if (collapseEl.classList.contains('show')) {
        icon.classList.remove('ri-arrow-right-s-line');
        icon.classList.add('ri-arrow-down-s-line');
      } else {
        icon.classList.remove('ri-arrow-down-s-line');
        icon.classList.add('ri-arrow-right-s-line');
      }
    }

    document.querySelectorAll('.kb-children').forEach(function (el) {
      // Initial state
      updateToggleIcon(el);
      el.addEventListener('shown.bs.collapse', function (e) { updateToggleIcon(e.target); });
      el.addEventListener('hidden.bs.collapse', function (e) { updateToggleIcon(e.target); });
    });
  }

  function initPopovers() {
    if (!window.bootstrap) return;
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
      if (bootstrap.Popover.getInstance(el)) return;
      var targetSel = el.getAttribute('data-popover-target');
      var contentHtml = '';
      if (targetSel) {
        var target = document.querySelector(targetSel);
        if (target) contentHtml = target.innerHTML;
      }
      try {
        new bootstrap.Popover(el, {
          html: true,
          trigger: 'click focus',
          sanitize: false,
          content: contentHtml,
          container: 'body',
          placement: 'top'
        });
      } catch (e) {}
    });

    // When opening one popover, close all others to keep UI tidy
    document.addEventListener('show.bs.popover', function (ev) {
      var current = ev.target;
      document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
        if (el === current) return;
        var inst = bootstrap.Popover.getInstance(el);
        if (inst) inst.hide();
      });
    });

    // Close all popovers on click outside
    document.addEventListener('click', function (ev) {
      var isInside = false;
      // If click is inside any popover, do nothing
      document.querySelectorAll('.popover').forEach(function (pop) {
        if (pop.contains(ev.target)) isInside = true;
      });
      // Or inside any trigger
      if (!isInside) {
        document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
          if (el.contains(ev.target)) isInside = true;
        });
      }
      if (!isInside) {
        document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
          var inst = bootstrap.Popover.getInstance(el);
          if (inst) inst.hide();
        });
      }
    });

    // Close on Escape key
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') {
        document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
          var inst = bootstrap.Popover.getInstance(el);
          if (inst) inst.hide();
        });
      }
    });
  }

  function initViewHandlers() {
    $(document).on('click', '.view-article-link, .view-article-btn', function (e) {
      e.preventDefault();
      const $t = $(this);
      window.viewArticle && window.viewArticle($t.data('id'), $t.data('title'), $t.data('content'), $t.data('labels'), $t.data('board'));
    });
  }

  function initEditHandlers() {
    $(document).on('click', '.kb-edit-btn', function (e) {
      e.preventDefault();
      const id = $(this).data('article-id');
      const $cont = $('#edit-container-' + id);
      $('.edit-container:visible').not($cont).each(function () {
        const $c = $(this);
        restoreTitleFromInput($c.closest('li.kb-item'));
        $c.slideUp(150);
      });
      if ($cont.is(':visible')) {
        // On collapsing editor, restore title anchor instead of textbox
        restoreTitleFromInput($cont.closest('li.kb-item'));
        $cont.slideUp(150, enableSortables);
        return;
      }
      if ($cont.is(':empty')) {
        openInlineEditor(id, $cont);
      } else {
        switchTitleToInput($cont.closest('li.kb-item'));
        disableSortables();
        $cont.slideDown(150);
      }
    });
  }

  $(function () {
    initViewHandlers();
    initEditHandlers();
    initSortable();
    initSearch();
    initCollapseIcons();
    initPopovers();
  });

})(jQuery);
