
        server_name game.syscall.ru;

        index index.html index.php;

        location / {
                # First attempt to serve request as file, then
                # as directory, then fall back to displaying a 404.
                try_files $uri $uri/ @redirect2main;
                error_page 404 = 500;
        }

        location @redirect2main {
                return 301 /;
        }

        location ~ \.php$ {
                fastcgi_split_path_info ^(.+\.php)(.*)$;
                include fastcgi_params;
                fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
                fastcgi_pass unix:/var/run/game.syscall.ru.sock;
        }

        location ~ /\.ht {
                deny all;
        }
