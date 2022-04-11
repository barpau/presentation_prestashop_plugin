/**
* 2019 Ximilar
*  @author Ximilar <info@ximilar.com>
*  @copyright  2019 Ximilar
*  @license https://www.ximilar.com
*/

jQuery(document).ready(function (eclick) {

    //standard indexing process
    jQuery(".reindex_cancel_button").bind("click", function (e) {
        clearInterval();
        jQuery(".status_text").html("Cancelling ...");
        var data_send = {
            to_do: "CANCEL_REINDEX"
        };
        jQuery.ajax({
            type: 'post',
            cache: false,
            url: full_store_url + '/modules/ximilarproductsimilarity/ajax_listener.php',
            data: data_send,
            success: function (data) {
                location.reload();
            }
        });
    });
    jQuery("button[name= 'submitCreateCollection']").bind("click", function (e) {
        var trial = jQuery("input[name= category_tree]").length == 0 ? false : true;
        var selected = jQuery('input[name= category_tree]:checked').val();
        if (!trial || selected > 0) {
            jQuery("button[name= 'submitCreateCollection']").hide(300);
            jQuery("button[name= 'submitFillRelatedItems']").hide(300);
            jQuery("button[name= 'submitSaveConfig']").hide(300);
            var category_id = selected > 0 ? selected : 0;
            var data_send = {
                to_do: "REINDEX",
                category_id: category_id,
            };
            jQuery("#dialog").dialog({dialogClass: "no-close"});
            jQuery.ajax({
                type: 'post',
                cache: false,
                url: full_store_url + '/modules/ximilarproductsimilarity/ajax_listener.php',
                data: data_send,
                success: function (data) {
                    if (data != '') {
                        jQuery("#dialog").dialog("close");
                        jQuery("#dialog_error").dialog();
                        jQuery(".status_text").html(data);
                        jQuery("button[name= 'submitCreateCollection']").show(300);
                        jQuery("button[name= 'submitFillRelatedItems']").show(300);
                        jQuery("button[name= 'submitSaveConfig']").show(300);
                    } else {
                        jQuery("#dialog").dialog("close");
                        jQuery("#dialog_success").dialog({
                            dialogClass: "no-close",
                            buttons: {
                                "OK": function () {
                                    location.reload();
                                }
                            }
                        });
                        jQuery("button[name= 'submitCreateCollection']").show(300);
                        jQuery("button[name= 'submitFillRelatedItems']").show(300);
                        jQuery("button[name= 'submitSaveConfig']").show(300);
                    }
                }, data: data_send
            })
        } else {
            alert("Please select category.");
        }
        e.preventDefault();
        return false;
    });

    //non-standard display of indexing process (e.g. in case of refreshing the page)
    if (jQuery("button[name= 'submitCreateCollection']").length == 0 && jQuery("button[name= 'submitAuthorizeToken']").length == 0) {
        jQuery("#dialog").dialog({dialogClass: "no-close"});
        setInterval(check_reindexing_status, 1000);
    }
    ;

    function check_reindexing_status()
    {
        var data_send = {
            to_do: "CHECK_REINDEX_STATUS"
        }
        jQuery.ajax({
            type: 'post',
            data: data_send,
            cache: false,
            url: full_store_url + '/modules/ximilarproductsimilarity/ajax_listener.php',
            success: function (data) {
                if (data == "reindex_done") {
                    jQuery("#dialog").dialog("close");
                    jQuery("#dialog_success").dialog({
                        dialogClass: "no-close",
                        buttons: {
                            "OK": function () {
                                location.reload();
                            }
                        }
                    });
                    jQuery("button[name= 'submitCreateCollection']").show(300);
                    jQuery("button[name= 'submitFillRelatedItems']").show(300);
                    jQuery("button[name= 'submitSaveConfig']").show(300);
                    clearInterval();
                }
            }
        });
    }
});
