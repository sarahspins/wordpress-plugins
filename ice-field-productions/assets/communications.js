(function($){
  function updateAudience(){
    var type=$('#ifp-recipient-type').val()||'production';
    $('.ifp-audience-panel').each(function(){
      var active=$(this).data('audience')===type;
      $(this).prop('hidden',!active).find('select,input').prop('disabled',!active);
    });
  }

  $(function(){
    $('#ifp-recipient-type').on('change',updateAudience);
    updateAudience();
  });
})(jQuery);
