# EasyPodcast — Instalador

Instalador automático de un solo archivo para [EasyPodcast](https://github.com/educollado/EasyPodcast). Descarga la última release desde GitHub, la extrae en el servidor y crea la base de datos SQLite, todo desde el navegador sin necesidad de acceso SSH ni herramientas adicionales.

## Requisitos

| Componente | Mínimo |
|---|---|
| PHP | 8.0+ |
| Extensiones PHP | `pdo_sqlite`, `sqlite3`, `fileinfo`, `xmlwriter`, `zip`, `gd` |
| Directorio de instalación | Escribible por el servidor web |
| Extracción del paquete | `phar` (PharData) **o** `exec()` habilitado con `tar` disponible |

**Recomendado:** Apache con `mod_rewrite` habilitado para URLs amigables de episodios.

## Instalación

1. Descarga `instalar.php` y súbelo al directorio raíz de tu servidor web donde quieras instalar EasyPodcast (debe estar vacío o ser el único archivo en ese directorio).

2. Abre el instalador en tu navegador:
   ```
   https://tu-dominio.com/instalar.php
   ```

3. Sigue los tres pasos del asistente:
   - **Compatibilidad** — verifica que el servidor cumple todos los requisitos.
   - **Directorio** — comprueba si el directorio está limpio; si hay archivos previos, puedes eliminarlos desde esta pantalla.
   - **Instalación** — descarga y extrae la última release de EasyPodcast desde GitHub y crea la base de datos.

4. Al finalizar serás redirigido al panel de administración (`/admin.php`).

> **Seguridad:** el instalador intenta borrarse a sí mismo al completarse. Si el aviso indica que no pudo eliminarse, **borra `instalar.php` manualmente** antes de usar la aplicación.

## Qué hace el instalador

1. Consulta la [API de releases de GitHub](https://api.github.com/repos/educollado/EasyPodcast/releases/latest) para obtener la versión más reciente.
2. Descarga el archivo `.tar.gz` de la release (usa cURL si está disponible, o `file_get_contents` como alternativa).
3. Extrae el paquete con `PharData` (preferido) o con `exec(tar)` como método de reserva.
4. Inicializa la base de datos SQLite ejecutando `schema.sql` mediante PDO o el CLI de `sqlite3`.
5. Crea los directorios `audios/` e `images/` para los archivos multimedia.
6. Se autoeliminan una vez completada la instalación.

## Estructura generada tras la instalación

```
tu-directorio/
├── admin.php          # Panel de administración
├── index.php          # Sitio público del podcast
├── podcast.sqlite     # Base de datos SQLite
├── schema.sql         # Esquema de la base de datos
├── audios/            # Archivos de audio de los episodios
└── images/            # Imágenes de episodios y portada
```

## Solución de problemas

| Problema | Solución |
|---|---|
| Error en la comprobación de extensiones | Activa las extensiones PHP requeridas en `php.ini` o contacta con tu proveedor de hosting |
| El directorio no es escribible | Ajusta los permisos: `chmod 755 /ruta/al/directorio` |
| Falla la extracción del paquete | Verifica que la extensión `phar` esté habilitada o que `exec()` no esté desactivada en `disable_functions` |
| No se puede crear la base de datos | Comprueba que las extensiones `pdo_sqlite` y `sqlite3` estén activas |
| No se pudo descargar el paquete | Verifica que el servidor tenga acceso a Internet y que cURL o `allow_url_fopen` estén habilitados |

## Licencia

Distribuido bajo la licencia [GNU General Public License v3.0](LICENSE).
