/*
 * AngularJS 1.5.8 controller — matches the framework version on the target site.
 *
 * Note how little there is here: this controller just fetches and renders
 * articles. It contains no QR logic at all. The QR embedding is handled
 * entirely by traceit-qr-thumbnail.js, which self-initialises from its own
 * script tag. That separation is the point — the publisher's application code
 * does not change to adopt this.
 */
angular.module('chronicle', [])
  .controller('HomeCtrl', ['$http', function ($http) {
    var vm = this;

    vm.articles = [];
    vm.origin = window.location.origin;

    $http.get('/api/articles').then(function (res) {
      vm.articles = res.data;
    });

    vm.time = function (iso) {
      return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    };
  }]);
