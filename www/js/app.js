window.App = {

    current : 0,
    nbArticles : 0,

    init: function() {
        App.nbArticles = $('.hn-article').length;
        this.makeLastUpdateReadable();
        this.buildNavigation();
        this.checkCache();
    },

    checkCache : function() {
        var appCache = window.applicationCache;
        appCache.addEventListener('cached', App.handleCacheEvent, false);
        appCache.addEventListener('checking', App.handleCacheEvent, false);
        appCache.addEventListener('downloading', App.handleCacheEvent, false);
        appCache.addEventListener('error', App.handleCacheError, false);
        appCache.addEventListener('noupdate', App.handleCacheEvent, false);
        appCache.addEventListener('obsolete', App.handleCacheEvent, false);
        appCache.addEventListener('progress', App.handleCacheEvent, false);
        appCache.addEventListener('updateready', App.handleCacheEvent, false);
    },

    handleCacheError: function() {
        $('.loader').hide();
    },
    handleCacheEvent: function() {
        switch (window.applicationCache.status) {
            case window.applicationCache.CHECKING:
            case window.applicationCache.DOWNLOADING:
                $('.loader').show();
                break;
            case window.applicationCache.UPDATEREADY:
                $('.loader').hide();
                $('#reload').show();
                break;
            default :
                $('.loader').hide();
        }
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