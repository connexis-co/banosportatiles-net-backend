/**
 * Block editor: after saving a published page/post/equipo, a snackbar «Guardado. El sitio público se actualiza
 * en ~4 min.» and a refresh of the publication state in the admin bar (publish-status.js).
 */
(function (wp) {
  'use strict';
  var cfg = window.bpPublishEditor;
  if (!cfg || !wp || !wp.data) {
    return;
  }
  var wasSaving = false;
  var wasPublished = false;
  wp.data.subscribe(function () {
    var editor = wp.data.select('core/editor');
    if (!editor || typeof editor.isSavingPost !== 'function') {
      return;
    }
    var saving = editor.isSavingPost() && !editor.isAutosavingPost();
    if (saving && !wasSaving) {
      wasPublished = editor.getCurrentPostAttribute('status') === 'publish';
    }
    if (wasSaving && !saving && editor.didPostSaveRequestSucceed()) {
      var published = editor.getCurrentPostAttribute('status') === 'publish';
      if (published || wasPublished) {
        wp.data.dispatch('core/notices').createNotice('info', cfg.saved, { id: 'bp-publish-saved', type: 'snackbar', isDismissible: true });
        window.dispatchEvent(new CustomEvent('bp:publish-refresh'));
      }
    }
    wasSaving = saving;
  });
})(window.wp);
