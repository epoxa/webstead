if (!Array.prototype.indexOf) {
  Array.prototype.indexOf = function (elt /*, from*/) {
    var len = this.length;

    var from = Number(arguments[1]) || 0;
    from = (from < 0)
      ? Math.ceil(from)
      : Math.floor(from);
    if (from < 0)
      from += len;

    for (; from < len; from++) {
      if (from in this &&
        this[from] === elt)
        return from;
    }
    return -1;
  };
}

var blind = null;
var robots = {};
var modified = [];
var xmlhttp;
var currentScript;

var outgoing = null;


var onreadystatechange = function () {
  if (xmlhttp.readyState == 4) {
    if (xmlhttp.status == 200) {
      try {
        eval("var changes = " + xmlhttp.responseText + ";");
        //noinspection JSUnresolvedVariable
        received(changes);
      } catch (e) {
        document.body.innerHTML = "<pre>" + e.message + ":\n" + xmlhttp.responseText + "</pre>";
      }
    } else {
      document.body.innerHTML = xmlhttp.responseText;
      blind.style.display = 'none';
    }
  }
};

function init() {
  blind = document.getElementById('blind');
  $(document.body, 'keydown', keyDown);
  xmlhttp = new XMLHttpRequest();
  go();
}

function changed(elem) {
  var idx = modified.indexOf(elem);
  if (idx == -1) modified.push(elem);
}

function go(robot, method, params) {
//      if (typeof(window["event"]) != "undefined") {
//        if(event.preventDefault) event.preventDefault(); else event.returnValue = false;
//      }

  var poststr = "view=" + viewId;
  if (robot != null) {
    poststr += "&who=" + encodeURIComponent(robot) + "&do=" + encodeURIComponent(method);
  }
  if (params) {
    for (var arg_name in params) {
      var arg_val = params[arg_name];
      poststr += "&" + arg_name + "=" + encodeURIComponent(arg_val);
    }
  }
  for (var idx in modified) {
    var el = modified[idx];
    if (el.tagName != 'INPUT' || el.type != 'file') {
      poststr = "" + poststr + "&" + el.id + "=" + encodeURIComponent(el.value);
    }
  }
  outgoing = poststr;

  if (window.onBeforeConnect) window.onBeforeConnect();
  blind.style.display = '';

  continue_send();
}

function continue_send() {

  for (var idx in modified) {
    if (modified.hasOwnProperty(idx)) {
      var el = modified[idx];
      if (el.tagName == 'INPUT' && el.type == 'file') {
        modified.splice(idx, 1);
        var form = el.form;
        form.target = 'upload_result';
        var result = document.getElementById('upload_result');
        result.onload = function () {
          setTimeout(continue_send, 0);
        };
        form.submit();
        return;
      }
    }
  }

  xmlhttp.open('POST', rootUrl, true);
  xmlhttp.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
  xmlhttp.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
//  xmlhttp.setRequestHeader('Cookie', document.cookie); // Не работает почему-то
//      xmlhttp.setRequestHeader("Content-length", poststr.length); // хром ругаеццо, что небезопасный заголовок
//      xmlhttp.setRequestHeader("Connection", "close");            // хром ругаеццо, что небезопасный заголовок
  xmlhttp.onreadystatechange = onreadystatechange;
  xmlhttp.send(outgoing);
  outgoing = null;
}

var pendingScrips = [];
var loadScrips = [];
var loadingCount;
var inlineTailScript = null;
var controlToFocus = null;

function received(changes) {
  var hasVisualChages = false;
  inlineTailScript = null;
  controlToFocus = null;
  for (var id in changes) {
    if (changes.hasOwnProperty(id)) {
      var data = changes[id];
      if (id[0] === '!') {
        var w = window;
        if (id === '!!') {
          w = w.top;
        }
        var restartUrl = data;
        if (restartUrl === null) {
          w.location.reload();
        } else {
          var thisUrlOk = false;
          try {
            thisUrlOk = w.location.href === restartUrl;
          } catch(e) {
          }
          if (thisUrlOk) w.location.reload()
          else w.location.href = restartUrl;
        }
        return;
      } else if (id[0] === '^') {
        appendHeader(data);
      } else if (id[0] === '<') {
        eval(data);
      } else if (id[0] === '>') {
        inlineTailScript = data;
      } else if (id[0] === '-') {
        id = id.substring(1);
        delete robots[id];
      } else {
        robots[id] = data;
        if (renderRobot(id, data, true)) hasVisualChages = true;
      }
    }
  }
  while (hasVisualChages) {
    hasVisualChages = false;
    for (var rid in robots) {
      if (robots.hasOwnProperty(rid)) {
        if (renderRobot(rid, robots[rid], false)) hasVisualChages = true;
      }
    }
  }

  // Динамическое выполнение локальных скриптов
  var allScripts = document.getElementsByTagName('script');
  loadScrips = [];
  for (var i = 0; i < allScripts.length; i++) {
    var script = allScripts[i];
    if (!script.getAttribute('ok')) {
      if (script.src) {
        loadScrips.push(script);
      } else {
        pendingScrips.push(script);
      }
      script.setAttribute('ok', '1');
    }
  }
  modified = [];
  loadingCount = loadScrips.length;
  if (loadingCount == 0) {
    continuePending();
  } else {
    for (i = 0; i < loadScrips.length; i++) {
      var oldScript = loadScrips[i];
      var src = oldScript.src;
      var xhr = new XMLHttpRequest();
      xhr.onreadystatechange = function () {
        if (xhr.readyState == 4) {
          if (xhr.status == 200 || xhr.status == 304) {
            var oHead = document.getElementsByTagName('HEAD').item(0);
            var newScript = document.createElement("script");
            newScript.language = "javascript";
            newScript.type = "text/javascript";
            newScript.defer = false;
            newScript.text = xhr.responseText;
            oldScript.parentNode.replaceChild(newScript, oldScript);
            newScript.setAttribute("ok", "1");
          } else if (xhr.status == 0) {
            console.log(xhr.responseText);
            document.body.innerHTML = src;
//            alert(xhr.responseText);
          } else {
            document.body.innerHTML = 'Script request error ("' + src + '"): ' + xhr.statusText + ' (' + xhr.status + ')';
          }
          console.log('script ' + src + ' loaded');
          loadingCount--;
          if (!loadingCount) {
            continuePending();
          }
        }
      }
      xhr.open('GET', src, true);
      xhr.send(null);
    }
  }

}

function continuePending() {
  for (var i = 0; i < pendingScrips.length; i++) {
    currentScript = pendingScrips[i];
    try {
      console.log('exec: ' + currentScript.text);
      eval(currentScript.text);
    } catch (e) {
      console.log('error: ' + e.message);
    }
    currentScript = null;
  }
  pendingScrips = [];

  if (inlineTailScript != null) {
    console.log('inline:' + inlineTailScript);
    eval(inlineTailScript);
  }

  setTimeout(function () {
      blind.style.display = 'none';
      if (window.onAfterDisconnect) window.onAfterDisconnect();
      if (controlToFocus) {
        controlToFocus.focus();
        controlToFocus = null;
      }
    }
    , 0
  );
}

function renderRobot(robot_id, robot_view, force) {
  var place = document.getElementById(robot_id); // TODO: Нужно, чтобы корректно обрабатывались несколько вхождений одного робота на страницу
  if (place && (force || !place.getAttribute('ok'))) {
    place.innerHTML = robot_view;
    place.setAttribute('ok', 1);
    return true;
  } else {
    return false;
  }
}

function appendHeader(text) {
  var head = document.getElementsByTagName('HEAD')[0];
  head.insertAdjacentHTML('beforeEnd', text);
  //document.body.innerHTML = document.body.innerHTML + text; // TODO: Понять, почему в IE только так работает
}

var keyDown = function (e) {
  if (blind.style.display == '') return false;
};

$(init);

//if (document.readyState == 'complete') {
//  init();
//} else {
//  $(window, 'load', init);
//}

// Service

window.setFocusElement = function (control) {
  if (control && control.focus) controlToFocus = control;
};

window.clickLink = function (link) {
  var cancelled = false;
  if (document.createEvent) {
    var event = document.createEvent("MouseEvents");
    event.initMouseEvent("click", true, true, window,
      0, 0, 0, 0, 0,
      false, false, false, false,
      0, null);
    cancelled = !link.dispatchEvent(event);
  }
  else if (link.fireEvent) {
    cancelled = !link.fireEvent("onclick");
  }
  if (!cancelled) {
    window.location = link.href;
  }
};

window.debugGoSource = function (editor, path) {
  alert("Source integration not implemented in Linux");
/*
  var o = document.createElement('object');
  o.setAttribute('type', 'application/x-itst-activex');
  o.setAttribute('clsid', '{4472D605-2D79-4C57-89FE-DC62FC65B905}');
  o.setAttribute('progid', 'ShellExecControl.Agent');
  var h = document.firstChild.nextSibling;
  h.appendChild(o);
  o.Execute(editor, path);
  h.removeChild(o);
*/
};

