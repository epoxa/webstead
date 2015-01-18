window.clearGameTimer = function() {
  clearTimeout(window.gameTimer);
};

window.setGameTimer = function(milliseconds) {
  clearGameTimer();
  if (milliseconds !== null) {
    window.gameTimer = setTimeout(function() {
      go(gameHandle, 'timer');
    }, milliseconds);
  }
};

window.cancelUseMode = function() {
  $('.inventory a').removeClass('dragging');
  $('.main, .inventory').removeClass('use-mode');
};

window.getDisplayText = function(obj) {
  obj = $(obj);
  var r = obj.text();
  if (r) return r;
  obj = obj.children('img');
  if (!obj) return '???';
  r = obj.attr("alt");
  if (r) return r;
  r = obj.attr("src");
  if (!r) return '???';
  return r.split("/").pop();
};

window.prepareDragAndDrop = function () {
  $("body").off("mouseover", ".inventory a").on("mouseover", ".inventory a", function (event) {
    var me = $(this);
    me.draggable({
      revert: true,
      revertDuration: 0,
      helper: 'clone',
      scroll: false,
      start: function (event, ui) {
        var el = this;
        el.saveOnClick = el.onclick;
        el.onclick = null;
        $(el).css('visibility', 'hidden');
        $('.main, .inventory').removeClass('use-mode');
        $('.inventory a').removeClass('dragging');
        ui.helper.addClass('dragging');
        $('.inventory a, .scene a').droppable({
          over: function (event, ui) {
            if (window.hoverDroppable) {
              $(window.hoverDroppable).css('visibility', 'visible');
              window.hoverDroppable = null;
            }
            $(this).css('visibility', 'hidden');
            window.hoverDroppable = this;
            ui.helper.html(ui.draggable.html() + "&nbsp;&rarr;&nbsp;" + $(this).html());
            ui.helper.addClass('highlight');
          },
          out: function (event, ui) {
            $(this).css('visibility', 'visible');
            if (window.hoverDroppable == this) {
              ui.helper.html(ui.draggable.html());
              ui.helper.removeClass('highlight');
              window.hoverDroppable = null;
            }
          },
          drop: function (event, ui) {
            event.stopPropagation();
            if (this != window.hoverDroppable) return;
            window.hoverDroppable = null;
            $(this).css('visibility', 'visible');
            go(gameHandle, "dropped", {
              s_src: ui.draggable.attr("name"),
              s_srcTitle: getDisplayText(ui.draggable),
              s_dest: $(this).attr("name"),
              s_destTitle: getDisplayText(this)
            });
          },
          activeClass: "active",
          hoverClass: "hover"
        });
      },
      stop: function (event, ui) {
        el = this;
        el.onclick = el.saveOnClick;
        el.saveOnClick = null;
        $(el).css('visibility', 'visible');
      }
    });
  });
};

window.prepareNoDrag = function () {
  $("body").off("click", ".scene a, .inventory a").on("click", ".scene a, .inventory a", function (event) {
    var me = $(this);
    var myName = me.attr("name");
    var isInventory = me.closest(".inventory").length > 0;
    var dragging = $('.inventory .dragging');
    if (dragging.get(0)) {
      go(gameHandle, "dropped", {
        s_src: dragging.attr("name"),
        s_srcTitle: getDisplayText(dragging),
        s_dest: myName,
        s_destTitle: getDisplayText(me)
      });
      $('.inventory a').removeClass('dragging');
      $('.main, .inventory').removeClass('use-mode');
    } else if (isInventory) {
      if (myName.match(/^act |^go /)) {
        go(gameHandle, 'inventoryClick', {s_obj: myName, s_title: getDisplayText(me)});
      } else {
        me.addClass('dragging');
        $('.main, .inventory').addClass('use-mode');
      }
    } else {
      go(gameHandle, 'press', {s_cmd: me.attr('name'), s_title: getDisplayText(me)});
    }
  });
};

window.prepareStrict = function () {
  $("body").off("click", ".scene a, .inventory a").on("click", ".scene a, .inventory a", function (event) {
    var me = $(this);
    var myName = me.attr("name");
    var myTitle = getDisplayText(me);
    var isInventory = me.closest(".inventory").length > 0;
    var dragging = $('.inventory .dragging');
    if (dragging.get(0)) {
      go(gameHandle, "dropped", {
        s_src: dragging.attr("name"),
        s_srcTitle: getDisplayText(dragging),
        s_dest: myName,
        s_destTitle: getDisplayText(me)
      });
      $('.inventory a').removeClass('dragging');
      $('.main, .inventory').removeClass('use-mode');
    } else {
      go(gameHandle, 'press', {s_cmd: myName, s_title: myTitle, i_fromInventory: isInventory});
    }
  });
};

window.findPreviousFocusable = function (elem, skip) {
  if (!elem) return null;
  var newFocus = elem;
  while (true) {
    if (newFocus.previousSibling) {
      newFocus = newFocus.previousSibling;
      while (newFocus.lastChild) newFocus = newFocus.lastChild;
    } else {
      newFocus = newFocus.parentNode;
    }
    if (!newFocus) return null;
    if (newFocus == document.body || newFocus.tabIndex > 0 && (!hasClass(newFocus, 'skip') || !skip) && getRealStyle(newFocus, 'display') != 'none' && getRealStyle(newFocus, 'visibility') != 'hidden') break;
  }
  if (newFocus.focus && newFocus != document.body) return newFocus;
  else return null;
};

window.findNextFocusable = function (elem, skip) {
  if (!elem) return null;
  var newFocus = elem;
  while (true) {
    if (newFocus.firstChild)
      newFocus = newFocus.firstChild;
    else if (newFocus.nextSibling)
      newFocus = newFocus.nextSibling;
    else {
      while (newFocus.parentNode && !newFocus.nextSibling && newFocus != document.body) newFocus = newFocus.parentNode;
      if (newFocus.nextSibling) newFocus = newFocus.nextSibling;
      else newFocus = null;
    }
    if (!newFocus) return null;
    if (newFocus.tabIndex > 0 && (!hasClass(newFocus, 'skip') || !skip) && getRealStyle(newFocus, 'display') != 'none' && getRealStyle(newFocus, 'visibility') != 'hidden') break;
  }
  if (newFocus && newFocus.focus) return newFocus;
  else return null;
};

window.hasClass = function (el, cls) {
  for (var c = el.className.split(' '), i = c.length - 1; i >= 0; i--) {
    if (c[i] == cls) return true;
  }
  return false;
};

window.getRealStyle = function (elem, part) {
  if (elem.currentStyle) {
    return elem.currentStyle[part];
  } else if (window.getComputedStyle) {
    var computedStyle = window.getComputedStyle(elem, null);
    return computedStyle.getPropertyValue(part);
  } else {
    return null;
  }
};

function tryFocus(id) {
  var e = document.getElementsByName(id);
  if (e.length) {
    e = e[0];
    if (!hasClass(e, 'skip')) {
      window.setFocusElement(e);
    }
  }
  if (!controlToFocus) {
    window.setFocusElement(findNextFocusable(document.body, true));
  }
}

$(document.body, 'keydown', function (event) {
  event = event || window.event;
  var target = event.target || event.srcElement;
  var newFocus;
//noinspection FallthroughInSwitchStatementJS
  switch (event.keyCode) {
    case 13:
      if (target.tagName == 'INPUT' && target.type == 'text' || target.tagName == 'TEXTAREA') {
        var next = findNextFocusable(target);
        if (next.tagName == 'A') {
          event.preventDefault ? event.preventDefault() : this.returnValue = false;
          next.focus();
          setTimeout(function () {
            eval(next.getAttribute('onclick'));
            // next.click()
          }, 0);
        }
      }
      break;
    case 37:
      if (target.tagName == 'INPUT' && target.type == 'text') return null;
      if (target.tagName == 'TEXTAREA') return null;
    case 38:
      newFocus = findPreviousFocusable(target, event.keyCode == 38);
      if (newFocus) {
        console.log('focus: ' + newFocus.tagName);
        newFocus.focus();
      }
      return false;
    case 39:
      if (target.tagName == 'INPUT' && target.type == 'text') return null;
      if (target.tagName == 'TEXTAREA') return null;
    case 40:
      newFocus = findNextFocusable(target, event.keyCode == 40);
      if (newFocus) {
        console.log('focus: ' + newFocus.tagName);
        newFocus.focus();
      }
      return false;
    default:
  }
  return null;
});
