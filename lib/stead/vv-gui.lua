local old_gamefile = gamefile;

stead.gamefile = function(file, forget)
    if file then file = game.gui.vvGameDir .. file end
    return old_gamefile(file, forget);
end

-- tmp = nil;

gamefile = stead.gamefile

old_dofile = dofile;

dofile = function(file)
    if string.sub(file, 1, 1) ~= '/' then
        file = game.gui.vvGameDir .. file
    end
    return old_dofile(file)
end

--[[

game.gui.inv_delim = '<br>';

iface.xref = function(self, str, obj, ...)
    local o = stead.ref(obj);
    local cmd = ''

    if not isObject(o) or isStatus(o) or (not o.id and not isXaction(o)) then
        return str;
    end

    if stead.ref(ways():srch(obj)) then
        cmd = 'go ';
    elseif isMenu(o) then
        cmd = 'act ';
    elseif isSceneUse(o) then
        cmd = 'use ';
    elseif isXaction(o) and not o.id then
        cmd = 'act ';
    end
    local a = ''
    local i
    local varg = { ... }
    for i = 1, stead.table.maxn(varg) do
        a = a .. ',' .. varg[i]
    end
    if isXaction(o) and not o.id then
        return stead.cat("<a href='javascript:void(0);' onclick='go(gameHandle,&quot;press&quot;,{s_cmd:&quot;" .. cmd .. stead.deref(obj) .. a .. "&quot;})'>", str, "</a>");
    end
    return stead.cat("<a href='javascript:void(0);' onclick='go(gameHandle,&quot;press&quot;,{s_cmd:&quot;" .. cmd .. "0" .. stead.tostr(o.id) .. a .. "&quot;})'>", str, "</a>");
end;

iface.img = function(self, str)
    if str == nil then return nil; end;
    return '<img src="' .. game.gui.vvRefBase .. str .. '">';
end;

iface.imgl = function(self, str)
    if str == nil then return nil; end;
    return '<img src="' .. game.gui.vvRefBase .. str .. '" style="float:left;clear:left">';
end;

iface.imgr = function(self, str)
    if str == nil then return nil; end;
    return '<img src="' .. game.gui.vvRefBase .. str .. '" style="float:right;clear:right">';
end;

--]]

