!function(n){"use strict";n.fn.emulateTransitionEnd=function(t){var i=!1,o=this;return n(this).one(n.support.transition.end,(function(){i=!0})),setTimeout((function(){i||n(o).trigger(n.support.transition.end)}),t),this},n((function(){n.support.transition=function(){var n=document.createElement("bootstrap"),t={WebkitTransition:"webkitTransitionEnd",MozTransition:"transitionend",OTransition:"oTransitionEnd otransitionend",transition:"transitionend"};for(var i in t)if(void 0!==n.style[i])return{end:t[i]}}()}))}(jQuery);