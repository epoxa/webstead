var playSound = function (source, channelName, loop) {
  var player = document.getElementById(channelName);
  var c = player.firstChild;
  if (c && !player.ended && !player.paused && c.src.slice(-(source + ".ogg").length, -".ogg".length) == source) return;
  while (c = player.firstChild) {
    player.removeChild(c);
  }
  var data_ogg = document.createElement("source");
  data_ogg.setAttribute('src', source + ".ogg");
  data_ogg.setAttribute('type', 'audio/ogg');
  player.appendChild(data_ogg);
  var data_mp3 = document.createElement("source");
  data_mp3.setAttribute('src', source + ".mp3");
  data_mp3.setAttribute('type', 'audio/mpeg');
  player.appendChild(data_mp3);
  player.loop = (loop == 0);
  player.load();
};

stopChannelNow = function (channelName) {
  var player = document.getElementById(channelName);
  player.pause();
};

stopChannels = function (channelIndexis) {
  for (var i = 0; i < channelIndexis.length; i++) {
    stopChannelNow("sound_" + channelIndexis[i]);
  }
};

stopSound = function () {
  stopChannels([0, 1, 2, 3, 4, 5, 6, 7]);
};
