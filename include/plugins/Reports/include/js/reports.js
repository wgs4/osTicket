<script>
$(document).ready(function(){

    $('[id^=MyRow_]').click(function(){
        $( ".sub_row" ).remove();
    });

    // Inital filter load
    var filter = $('#reportSelect').val();
            $.ajax({
                url: 'addFilter.php',
                type: 'post',
                data: {post_filter:filter},
                success:function(response){
                    var json_obj = $.parseJSON(response);
                    for(var key in json_obj) {
                        var thisentry = json_obj[key];
                        var id=thisentry.id;
                        var name=thisentry.name;
                            $("#report_filter").append("<option value='"+id+"'>"+name+"</option>");
                    }
                },
                error:function(textStatus, errorThrown) {
                    Success = false;
                }
            });


    // Change filter options on report type change
    $("#reportSelect").change(function () {
        var filter = $(this).val();
        // Remove all previous set options
        $('#report_filter')
            .find('option')
            .remove()
            .end()
            ;
            $("#report_filter").append("<option value='0'>-- No Filter --</option>");

            $.ajax({
                url: 'addFilter.php',
                type: 'post',
                data: {post_filter:filter},
                success:function(response){
                    var json_obj = $.parseJSON(response);
                    for(var key in json_obj) {
                        var thisentry = json_obj[key];
                        var id=thisentry.id;
                        var name=thisentry.name;
                            $("#report_filter").append("<option value='"+id+"'>"+name+"</option>");
                    }
                },
                error:function(textStatus, errorThrown) {
                    Success = false;
                }
            });
    });

    $('[id^=MyCreatedRow_]').click(function(){
        $( ".sub_row" ).remove();
        var subq = $(this).attr('data-query');

        var thisid = event.target.id;
        var thenum = thisid.replace( /^\D+/g, '');

        $.ajax({
            url: 'addSubRows.php',
            type: 'post',
            data: {post_subq:subq},
            success:function(response){

                var json_obj = $.parseJSON(response);
                for(var key in json_obj) {
                var thisone = json_obj[key];

                var timevar = thisone.time_to_res;
                if(timevar === false){
                        var timevar = 'N/A';
                }else{
                        var timevar = thisone.time_to_res;
                }

                $('<tr class="sub_row"><td><a href="tickets.php?id=' + thisone.ticket_id + '">' + thisone.number + '</a></td><td>' + thisone.status + '</td><td colspan=2>' + thisone.orgname + '</td><td>' + thisone.name + '</td><td colspan=2>' + thisone.subject + '</td><td>' + timevar + '</td></tr>').insertAfter( '#MyRow_' + thenum );
                }

            },
            error: function (textStatus, errorThrown) {
                Success = false;//doesnt goes here
            }
        });
    });

    $('[id^=MyAssignedRow_]').click(function(){
        $( ".sub_row" ).remove();
        var subq = $(this).attr('data-query');

        var thisid = event.target.id;
        var thenum = thisid.replace( /^\D+/g, '');

        $.ajax({
            url: 'addSubRows.php',
            type: 'post',
            data: {post_subq:subq},
            success:function(response){

                var json_obj = $.parseJSON(response);
                for(var key in json_obj) {
                var thisone = json_obj[key];
                var timevar = thisone.time_to_res;
                if(timevar === false){
                        var timevar = 'N/A';
                }else{
                        var timevar = thisone.time_to_res;
                }

                $('<tr class="sub_row"><td><a href="tickets.php?id=' + thisone.ticket_id + '">' + thisone.number + '</a></td><td>' + thisone.status + '</td><td colspan=2>' + thisone.orgname + '</td><td>' + thisone.name + '</td><td colspan=2>' + thisone.subject + '</td><td>' + timevar + '</td></tr>').insertAfter( '#MyRow_' + thenum );
                }

            },
            error: function (textStatus, errorThrown) {
                Success = false;//doesnt goes here
            }
        });
    });
    $('[id^=MyOverdueRow_]').click(function(){
        $( ".sub_row" ).remove();
        var subq = $(this).attr('data-query');

        var thisid = event.target.id;
        var thenum = thisid.replace( /^\D+/g, '');

        $.ajax({
            url: 'addSubRows.php',
            type: 'post',
            data: {post_subq:subq},
            success:function(response){

                var json_obj = $.parseJSON(response);
                for(var key in json_obj) {
                var thisone = json_obj[key];
                var timevar = thisone.time_to_res;
                if(timevar === false){
                        var timevar = 'N/A';
                }else{
                        var timevar = thisone.time_to_res;
                }

                $('<tr class="sub_row"><td><a href="tickets.php?id=' + thisone.ticket_id + '">' + thisone.number + '</a></td><td>' + thisone.status + '</td><td colspan=2>' + thisone.orgname + '</td><td>' + thisone.name + '</td><td colspan=2>' + thisone.subject + '</td><td>' + timevar + '</td></tr>').insertAfter( '#MyRow_' + thenum );
                }

            },
            error: function (textStatus, errorThrown) {
                Success = false;//doesnt goes here
            }
        });
    });

    $('[id^=MyReopenedRow_]').click(function(){
        $( ".sub_row" ).remove();
        var subq = $(this).attr('data-query');

        var thisid = event.target.id;
        var thenum = thisid.replace( /^\D+/g, '');

        $.ajax({
            url: 'addSubRows.php',
            type: 'post',
            data: {post_subq:subq},
            success:function(response){

                var json_obj = $.parseJSON(response);
                for(var key in json_obj) {
                var thisone = json_obj[key];
                var timevar = thisone.time_to_res;
                if(timevar === false){
                        var timevar = 'N/A';
                }else{
                        var timevar = thisone.time_to_res;
                }

                $('<tr class="sub_row"><td><a href="tickets.php?id=' + thisone.ticket_id + '">' + thisone.number + '</a></td><td>' + thisone.status + '</td><td colspan=2>' + thisone.orgname + '</td><td>' + thisone.name + '</td><td colspan=2>' + thisone.subject + '</td><td>' + timevar + '</td></tr>').insertAfter( '#MyRow_' + thenum );
                }

            },
            error: function (textStatus, errorThrown) {
                Success = false;//doesnt goes here
            }
        });
    });

    $('[id^=MyClosedRow_]').click(function(){
        $( ".sub_row" ).remove();
        var subq = $(this).attr('data-query');

        var thisid = event.target.id;
        var thenum = thisid.replace( /^\D+/g, '');

        $.ajax({
            url: 'addSubRows.php',
            type: 'post',
            data: {post_subq:subq},
            success:function(response){

                var json_obj = $.parseJSON(response);
                for(var key in json_obj) {
                var thisone = json_obj[key];
                var timevar = thisone.time_to_res;
                if(timevar === false){
                        var timevar = 'N/A';
                }else{
                        var timevar = thisone.time_to_res;
                }

                $('<tr class="sub_row"><td><a href="tickets.php?id=' + thisone.ticket_id + '">' + thisone.number + '</a></td><td>' + thisone.status + '</td><td colspan=2>' + thisone.orgname + '</td><td>' + thisone.name + '</td><td colspan=2>' + thisone.subject + '</td><td>' + timevar + '</td></tr>').insertAfter( '#MyRow_' + thenum );
                }

            },
            error: function (textStatus, errorThrown) {
                Success = false;//doesnt goes here
            }
        });
    });

    $('[id^=MyResolvedRow_]').click(function(){
        $( ".sub_row" ).remove();
        var subq = $(this).attr('data-query');

        var thisid = event.target.id;
        var thenum = thisid.replace( /^\D+/g, '');

        $.ajax({
            url: 'addSubRows.php',
            type: 'post',
            data: {post_subq:subq},
            success:function(response){

                var json_obj = $.parseJSON(response);
                for(var key in json_obj) {
                var thisone = json_obj[key];
                var timevar = thisone.time_to_res;
                if(timevar === false){
                        var timevar = 'N/A';
                }else{
                        var timevar = thisone.time_to_res;
                }

                $('<tr class="sub_row"><td><a href="tickets.php?id=' + thisone.ticket_id + '">' + thisone.number + '</a></td><td>' + thisone.status + '</td><td colspan=2>' + thisone.orgname + '</td><td>' + thisone.name + '</td><td colspan=2>' + thisone.subject + '</td><td>' + timevar + '</td></tr>').insertAfter( '#MyRow_' + thenum );
                }

            },
            error: function (textStatus, errorThrown) {
                Success = false;//doesnt goes here
            }
        });
    });


});
$("body").on("click", "label", function(e) {
  var getValue = $(this).attr("for");
  var goToParent = $(this).parents(".select-days");
  var getInputRadio = goToParent.find("input[id = " + getValue + "]");
  console.log(getInputRadio.attr("id"));
});
$("body").on("click", "label", function(e) {
  var getValue = $(this).attr("for");
  var goToParent = $(this).parents(".select-size");
  var getInputRadio = goToParent.find("input[id = " + getValue + "]");
  console.log(getInputRadio.attr("id"));
});
</script>
