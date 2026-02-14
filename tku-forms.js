(function($){
  'use strict';

  var lastClicked = null;

  // Track which submit button the user clicked (important when there are multiple actions)
  $(document).on('click', '.tku-form button[type="submit"], .tku-form input[type="submit"]', function(){
    lastClicked = this;
  });

  $(document).on('submit', '.tku-form', function(e){
    var $form = $(this);

    // Prevent double submits
    if ($form.data('tkuSubmitting')) {
      e.preventDefault();
      return false;
    }
    $form.data('tkuSubmitting', true);

    // Mark loading for CSS
    $form.addClass('tku-loading');

    // Ensure the clicked submit's name/value is included even if the button gets disabled
    if (lastClicked && $.contains(this, lastClicked)) {
      var $b = $(lastClicked);
      var name = $b.attr('name');
      var value = $b.val();

      if (name) {
        // Add/update a hidden field with the same name/value
        var $hidden = $form.find('input[type="hidden"][name="'+name.replace(/"/g,'\\"')+'"]');
        if ($hidden.length) {
          $hidden.last().val(value);
        } else {
          $('<input>').attr({type:'hidden', name:name, value:value}).appendTo($form);
        }
      }

      // Optionally change the clicked button label for nicer UX
      if (!$b.data('tkuOrigText')) {
        $b.data('tkuOrigText', $b.is('input') ? $b.val() : $b.text());
      }
      if ($b.is('input')) {
        $b.val('Feldolgozás...');
      } else {
        $b.text('Feldolgozás...');
      }

      // Disable the clicked button on the next tick (after the browser captured form data)
      setTimeout(function(){
        $b.prop('disabled', true);
      }, 0);
    }

    // Disable other submit buttons immediately (keeps UX consistent without breaking submission data)
    var $buttons = $form.find('button[type="submit"], input[type="submit"]');
    $buttons.each(function(){
      if (this !== lastClicked) $(this).prop('disabled', true);
    });

    return true;
  });


  function updateConditionals($form, condName){
    if (!$form || !$form.length) return;

    var val = '';
    var $checked = $form.find('input[name="'+condName+'"]:checked');
    if ($checked.length) val = $checked.val();

    $form.find('.tku-conditional[data-cond="'+condName+'"]').each(function(){
      var $wrap = $(this);
      var showVal = ($wrap.data('show') || '').toString();
      var required = ($wrap.data('required') || 0) == 1;
      var shouldShow = (val && val.toString() === showVal);

      if (shouldShow) {
        $wrap.show();
        if (required) {
          $wrap.find('input,select,textarea').prop('required', true);
        }
      } else {
        // hide and clear
        $wrap.hide();
        $wrap.find('input,select,textarea').each(function(){
          $(this).prop('required', false);
          if ($(this).is('input,textarea')) $(this).val('');
          if ($(this).is('select')) $(this).prop('selectedIndex', 0);
        });
      }
    });
  }

  function initAllConditionals(context){
    var $ctx = context ? $(context) : $(document);
    $ctx.find('.tku-form').each(function(){
      var $form = $(this);
      var conds = {};
      $form.find('.tku-conditional').each(function(){
        var name = $(this).data('cond');
        if (name) conds[name] = true;
      });
      Object.keys(conds).forEach(function(name){
        updateConditionals($form, name);
      });
    });
  }

  $(document).on('change', '.tku-form input[type="radio"]', function(){
    var $form = $(this.form);
    var name = $(this).attr('name');
    if (name) updateConditionals($form, name);
  });

  $(document).ready(function(){
    initAllConditionals(document);
  });


})(jQuery);
