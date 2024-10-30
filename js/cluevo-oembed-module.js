jQuery(document).ready(function() {
  jQuery('.cluevo-module-link.cluevo-module-mode-lightbox').click(async function(e) {
    e.preventDefault();
    var data = jQuery(this).data();
    var type = data.moduleType;
    var itemId = data.itemId;
    var moduleId = data.moduleId;
    if (type === 'oembed') {
      initApiWithItem(itemId, async function(response) {
        if (response.access) {
          var response = await cluevo_ext_oembed_get_module(moduleId);
          if (response) {
            cluevoOpenLightbox(data, "", response.html);
            cluevoShowLightbox();
          }
        } else {
          cluevoAlert(cluevoStrings.message_title_access_denied, cluevoStrings.message_access_denied, 'error');
        }
      });
    }
  });
  jQuery('.cluevo-module-link.cluevo-module-mode-popup').click(async function(e) {
    e.preventDefault();
    var data = jQuery(this).data();
    var type = data.moduleType;
    var itemId = data.itemId;
    var moduleId = data.moduleId;
    if (type === 'oembed') {
      var oembedWindow = window.open(jQuery(this).attr('href'));
      oembedWindow.onbeforeunload = function(e) {
        cluevo_ext_oembed_save_progress(moduleId, 100, 100);
      };
    }
  });

  jQuery(document).on(
    'click',
    '#cluevo-module-lightbox-overlay[data-module-type="oembed"] div.cluevo-close-button',
    function() {
      console.log("close");
      if (jQuery('#cluevo-module-lightbox-overlay').data('module-type') == "oembed") {
        var moduleId = jQuery('#cluevo-module-lightbox-overlay').data('module-id');
        var itemId = jQuery('#cluevo-module-lightbox-overlay').data('item-id');
        cluevo_ext_oembed_save_progress(itemId, 100, 100);
      }
      closeLightbox();
    }
  );
});

function cluevo_ext_oembed_save_progress(itemId, max, score) {
  var data = {
    id: itemId,
    max: max,
    score: score
  };

  var url = '/wp-json/cluevo/v1/items/' + itemId + '/progress';
  jQuery.ajax({
    url: url,
    method: 'POST',
    contentType: 'application/json',
    dataType: 'json',
    data: JSON.stringify(data),
    beforeSend: function(xhr) {
      xhr.setRequestHeader('X-WP-Nonce', cluevoWpApiSettings.nonce);
    },
    success: function(response) {
      // TODO: Handle succcess
    }
  });
}

async function cluevo_ext_oembed_get_module(moduleId) {
  var url = cluevoWpApiSettings.root + 'cluevo/v1/extensions/oembed/modules/' + moduleId;
  var result = false;
  await jQuery.ajax({
    url: url,
    method: 'GET',
    contentType: 'application/json',
    dataType: 'json',
    beforeSend: function(xhr) {
      xhr.setRequestHeader('X-WP-Nonce', cluevoWpApiSettings.nonce);
    },
    success: function(response) {
      result = response;
    }
  });
  return result;
}
