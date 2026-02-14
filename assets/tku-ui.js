(function(){
  'use strict';

  function prefersReducedMotion(){
    try {
      return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch(e){
      return false;
    }
  }

  function initReveal(){
    if (prefersReducedMotion()) {
      document.querySelectorAll('.tku-reveal').forEach(function(el){
        el.classList.add('tku-visible');
      });
      return;
    }

    var els = Array.prototype.slice.call(document.querySelectorAll('.tku-anim .tku-reveal'));
    if (!els.length) return;

    if (!('IntersectionObserver' in window)) {
      els.forEach(function(el){ el.classList.add('tku-visible'); });
      return;
    }

    var io = new IntersectionObserver(function(entries){
      entries.forEach(function(entry){
        if (entry.isIntersecting) {
          entry.target.classList.add('tku-visible');
          io.unobserve(entry.target);
        }
      });
    }, { root: null, threshold: 0.12 });

    els.forEach(function(el){ io.observe(el); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initReveal);
  } else {
    initReveal();
  }
})();
