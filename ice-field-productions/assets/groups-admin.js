jQuery(function($){
  'use strict';

  let participantIndex = $('.ifp-participant-row').length;

  $(document).on('click', '.ifp-add-participant', function(e){
    e.preventDefault();

    const template = $('#ifp-participant-row-template').html();
    if (!template) return;

    const row = template.replaceAll('__INDEX__', participantIndex);
    $('.ifp-participants-rows').append(row);
    participantIndex += 1;
  });

  $(document).on('click', '.ifp-remove-participant', function(e){
    e.preventDefault();
    $(this).closest('.ifp-participant-row').remove();
  });
});
