(function () {
  "use strict";

  var MAPS_KEY = "AIzaSyBPqCvvMUXuw600mWnPJtJU8i7V9eUMkUE";
  var mapsLoading = false;

  function loadGoogleMaps(callbackName) {
    if (window.google && window.google.maps) {
      if (typeof window[callbackName] === "function") {
        window[callbackName]();
      }
      return;
    }
    if (mapsLoading) {
      return;
    }
    mapsLoading = true;

    var script = document.createElement("script");
    script.src =
      "https://maps.googleapis.com/maps/api/js?key=" +
      MAPS_KEY +
      "&callback=" +
      callbackName;
    script.async = true;
    document.body.appendChild(script);
  }

  window.observeMapLazy = function (callbackName) {
    var mapEl = document.getElementById("map");
    if (!mapEl) {
      return;
    }

    if ("IntersectionObserver" in window) {
      var observer = new IntersectionObserver(
        function (entries) {
          if (entries[0].isIntersecting) {
            loadGoogleMaps(callbackName);
            observer.disconnect();
          }
        },
        { rootMargin: "200px" }
      );
      observer.observe(mapEl);
    } else {
      loadGoogleMaps(callbackName);
    }
  };
})();
