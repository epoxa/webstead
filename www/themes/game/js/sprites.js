reloadSprites = function (spritesList) {
    for (var i = 0; i < document.images.length; i++) {
        var img = document.images[i];
        for (var j = 0; j < spritesList.length; j++) {
            if (img.src.indexOf(spritesList[j]) != -1) {
                if (/dummy/.test(img.src)) {
                    img.src = img.src.replace(/dummy=.*$/,"dummy=" + (new Date()).toString());
                } else {
                    img.src += "&dummy=" + (new Date()).toString();
                }
            }
        }
    }
};