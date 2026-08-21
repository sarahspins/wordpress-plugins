jQuery(function ($) {
  'use strict';

  if (typeof inlineEditPost === 'undefined') return;

  const originalEdit = inlineEditPost.edit;

  inlineEditPost.edit = function (id) {
    originalEdit.apply(this, arguments);

    const postId = typeof id === 'object'
      ? parseInt(this.getId(id), 10)
      : parseInt(id, 10);

    if (!postId) return;

    const scope = $('#post-' + postId)
      .find('.column-ifp_production_scope .ifp-scope-value')
      .data('scope');

    if (typeof scope !== 'undefined') {
      $('#edit-' + postId)
        .find('select[name="ifp_production_scope"]')
        .val(String(scope));
    }
  };

  const originalSetBulk = inlineEditPost.setBulk;
  inlineEditPost.setBulk = function () {
    originalSetBulk.apply(this, arguments);

    $('#bulk-edit')
      .find('select[name="ifp_production_scope"]')
      .val('__no_change__');
  };
});
