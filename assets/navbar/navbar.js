require("./navbar.less");

var $ = require("jquery");

module.exports = {};

module.exports = {
    init: function() {
        $('.navbar .online a').each(function() {
            var 
                $modal = null,
                $body = null,
                $btnRefresh = null,
                url = $(this).attr('href');
            
            function reload() {
                if (!$modal) {
                    $modal = $(require('html!./online.html'));
                    $body = $modal.find('.modal-body');
                    $btnRefresh = $modal.find('.btn-primary').on('click', function(e) {
                        e.preventDefault();
                        reload();
                    });
                }
                $body.empty();
                $modal.modal();
                
                $btnRefresh.button('loading');
                $.get(url, {}, function(html) {
                    $body.html(html);
                    $btnRefresh.button('reset');
                });
            }
            
            $(this).on('click', function(e) {
                e.preventDefault();
                reload();
            });
        });
    }
};