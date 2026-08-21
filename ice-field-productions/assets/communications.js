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

    var attachmentFrame;
    $('.ifp-add-attachments').on('click',function(){
      var list=$(this).siblings('.ifp-attachment-list');
      attachmentFrame=wp.media({title:'Choose email attachments',button:{text:'Add attachments'},multiple:'add'});
      attachmentFrame.on('select',function(){
        var existing={};
        list.find('input').each(function(){existing[String($(this).val())]=true;});
        attachmentFrame.state().get('selection').each(function(model){
          var file=model.toJSON();
          if(existing[String(file.id)]||list.children().length>=5)return;
          existing[String(file.id)]=true;
          var name=$('<div>').text(file.filename||file.title||'Selected file').html();
          list.append('<div class="ifp-attachment-row"><input type="hidden" name="ifp_attachment_ids[]" value="'+file.id+'"><span>'+name+'</span><button type="button" class="button-link-delete ifp-remove-attachment">Remove</button></div>');
        });
      });
      attachmentFrame.open();
    });
    $(document).on('click','.ifp-remove-attachment',function(){$(this).closest('.ifp-attachment-row').remove();});
  });
})(jQuery);
