window.App = {

    current : 0,
    nbArticles : 0,

    init: function() {
        App.nbArticles = $('.hn-article').length;
        this.makeLastUpdateReadable();
        this.buildNavigation();
    },

    scrollToIndex : function(idx) {
        var selector = '.hn-article[data-index='+idx+']';
        if ($('.hn-article[data-index='+idx+']').length) {
            var scroll = $(selector).position().top;
            $(window).scrollTop(scroll);
            setTimeout(function(){
                App.current = idx;
            }, 100);
        }
    },

    makeLastUpdateReadable: function() {
        var $lastUpdate = $('#lastupdate');
        var lastupdate = moment($lastUpdate.attr("title"));
        console.log(lastupdate);
        $lastUpdate.html(lastupdate.fromNow());
    },

    buildNavigation: function() {
        $('.toc a').on('click', function(e){
            e.preventDefault();
            var index = $(this).attr('href').replace('#article-','');
            App.scrollToIndex(index);
        });
        $('.hn-article').waypoint(function() {
            App.current = parseInt($(this).attr('data-index'),10);
        });
        $('#next').on('click', function(e){
            e.preventDefault();
            var next = Math.min(App.nbArticles, App.current + 1);
            App.scrollToIndex(next);
        });
        $('#prev').on('click', function(e){
            e.preventDefault();
            var prev = Math.max(0, (App.current - 1));
            App.scrollToIndex(prev);
        });
        $('#index').on('click', function(e) {
            e.preventDefault();
            $(window).scrollTop(0);
        });
    }
};