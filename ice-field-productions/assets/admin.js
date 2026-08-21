
jQuery(function($){
  $('.ifp-tab').on('click', function(){
    const tab = $(this).data('tab');
    $('.ifp-tab').removeClass('is-active');
    $(this).addClass('is-active');
    $('.ifp-panel').removeClass('is-active');
    $('.ifp-panel[data-panel="'+tab+'"]').addClass('is-active');
  });
});


jQuery(function($){
  const config = window.IFPPagePicker || {};
  const pages = Array.isArray(config.pages) ? config.pages : [];
  if (!pages.length) return;

  function pageLabel(page) {
    let depth = 0;
    let parent = page.parent;
    const visited = {};
    while (parent && !visited[parent]) {
      visited[parent] = true;
      const ancestor = pages.find(function(candidate){ return candidate.id === parent; });
      if (!ancestor) break;
      depth++;
      parent = ancestor.parent;
    }
    return Array(depth + 1).join('— ') + (page.title || '(Untitled)');
  }

  $('input[type="url"]').each(function(){
    const input = $(this);
    if (input.hasClass('ifp-no-page-picker') || input.closest('.ifp-link-manager').length || input.siblings('.ifp-url-page-picker').length) return;

    const picker = $('<select class="ifp-url-page-picker" aria-label="Choose a WordPress page"></select>');
    picker.append($('<option value=""></option>').text(config.label || '— Choose a WordPress page —'));
    pages.forEach(function(page){
      if (!page.url) return;
      picker.append($('<option></option>').val(page.url).text(pageLabel(page)));
    });
    picker.on('change', function(){
      if (this.value) input.val(this.value).trigger('change');
    });
    input.after(picker);
  });
});


jQuery(function($){
  let ifpMediaFrame;

  $(document).on('click', '.ifp-select-media', function(e){
    e.preventDefault();
    const field = $(this).closest('.ifp-media-field');
    const frameTitle = field.data('media-title') || 'Choose Image';
    const frameButton = field.data('media-button') || 'Use This Image';

    ifpMediaFrame = wp.media({
      title: frameTitle,
      button: { text: frameButton },
      multiple: false,
      library: { type: 'image' }
    });

    ifpMediaFrame.on('select', function(){
      const attachment = ifpMediaFrame.state().get('selection').first().toJSON();
      field.find('.ifp-media-id').val(attachment.id);
      field.find('.ifp-media-preview').html('<img src="' + attachment.url + '" alt="">');
      field.find('.ifp-remove-media').show();
    });

    ifpMediaFrame.open();
  });

  $(document).on('click', '.ifp-remove-media', function(e){
    e.preventDefault();
    const field = $(this).closest('.ifp-media-field');
    const emptyLabel = field.data('empty-label') || 'No image selected';
    field.find('.ifp-media-id').val('');
    field.find('.ifp-media-preview').html('<span>' + $('<div>').text(emptyLabel).html() + '</span>');
    $(this).hide();
  });
});


jQuery(function($){
  let mediaUrlFrame;

  $(document).on('click', '.ifp-select-media-url', function(e){
    e.preventDefault();
    const field = $(this).closest('.ifp-media-url-field');
    const libraryType = field.data('library-type') || '';
    const options = {
      title: field.data('media-title') || 'Choose Media',
      button: { text: field.data('media-button') || 'Use This Media' },
      multiple: false
    };

    if (libraryType) {
      options.library = { type: libraryType };
    }

    mediaUrlFrame = wp.media(options);
    mediaUrlFrame.on('select', function(){
      const attachment = mediaUrlFrame.state().get('selection').first().toJSON();
      field.find('.ifp-media-url').val(attachment.url).trigger('change');
    });
    mediaUrlFrame.open();
  });
});


jQuery(function($){
  function updateResourceSourcePanels() {
    const source = $('input[name="ifp_resource_source"]:checked').val() || 'page';
    $('.ifp-resource-source-panel').hide();
    $('.ifp-resource-source-panel[data-source="' + source + '"]').show();
  }

  $(document).on('change', 'input[name="ifp_resource_source"]', updateResourceSourcePanels);
  updateResourceSourcePanels();

  let resourceMediaFrame;

  $(document).on('click', '.ifp-select-resource-media', function(e){
    e.preventDefault();
    const field = $(this).closest('.ifp-resource-media-field');

    resourceMediaFrame = wp.media({
      title: 'Choose Resource File',
      button: { text: 'Use This File' },
      multiple: false
    });

    resourceMediaFrame.on('select', function(){
      const attachment = resourceMediaFrame.state().get('selection').first().toJSON();
      const filename = attachment.filename || attachment.title || 'Selected file';

      field.find('.ifp-resource-media-id').val(attachment.id);
      field.find('.ifp-resource-media-preview').html(
        '<span class="dashicons dashicons-media-document"></span>' +
        '<div><strong>' + $('<div>').text(attachment.title || filename).html() + '</strong><br>' +
        '<small>' + $('<div>').text(filename).html() + '</small></div>'
      );
      field.find('.ifp-select-resource-media').text('Change File');
      field.find('.ifp-remove-resource-media').show();
    });

    resourceMediaFrame.open();
  });

  $(document).on('click', '.ifp-remove-resource-media', function(e){
    e.preventDefault();
    const field = $(this).closest('.ifp-resource-media-field');

    field.find('.ifp-resource-media-id').val('');
    field.find('.ifp-resource-media-preview').html(
      '<span class="dashicons dashicons-upload"></span><span>No media file selected</span>'
    );
    field.find('.ifp-select-resource-media').text('Select File');
    $(this).hide();
  });
});


jQuery(function($){
  const startDate = $('input[name="ifp_event_date"]');
  const endDate = $('input[name="ifp_event_end_date"]');

  if (!startDate.length || !endDate.length) {
    return;
  }

  function validateEventDateRange() {
    const start = startDate.val();
    const end = endDate.val();

    endDate.attr('min', start || null);
    endDate[0].setCustomValidity(
      start && end && end < start
        ? 'End Date must be on or after Start Date.'
        : ''
    );
  }

  startDate.on('change input', validateEventDateRange);
  endDate.on('change input', validateEventDateRange);
  validateEventDateRange();
});
