# 🏫 Bridges Note - MINEDU ESCALE Bridge API

Microservicio y API REST en **Laravel 13 (PHP 8.3)** diseñado para sincronizar, estructurar y servir el catálogo oficial de instituciones educativas (colegios) y la división política/geográfica (regiones, provincias y distritos) del Perú, consumiendo directamente la API pública oficial de **MINEDU / ESCALE** (*Estadística de la Calidad Educativa*).

---

## 📋 Tabla de Contenidos

1. [Propósito del Proyecto](#-propósito-del-proyecto)
2. [Arquitectura y Flujo de Datos](#-arquitectura-y-flujo-de-datos)
3. [Requisitos del Sistema](#-requisitos-del-sistema)
4. [Instalación y Puesta en Marcha](#-instalación-y-puesta-en-marcha)
5. [Configuración de Entorno (.env)](#-configuración-de-entorno-env)
6. [Sincronización de Datos (Consola Artisan)](#-sincronización-de-datos-consola-artisan)
7. [Documentación de la API REST](#-documentación-de-la-api-rest)
8. [Estructura del Proyecto](#-estructura-del-proyecto)
9. [Solución de Problemas Frecuentes](#-solución-de-problemas-frecuentes)

---

## 🎯 Propósito del Proyecto

Servir como un **puente de datos optimizado** para aplicaciones cliente (sistemas de admisión universitaria, plataformas de matrícula escolar, intranets, etc.), permitiéndoles autocompletar ubigeos y consultar colegios mediante consultas locales ultrarrápidas, sin depender de la latencia o disponibilidad de los servidores estatales.

---

## 🔄 Arquitectura y Flujo de Datos

```
[ API MINEDU / ESCALE ] 
 (escale.minedu.gob.pe)
          │
          ▼  (Comando: php artisan sync:locations)
[ Base de Datos Local MySQL (escalebridge) ]
  ├── regions
  ├── provinces
  ├── districts
  └── schools (con ubigeo / idDistrito)
          │
          ▼  (LocationController)
[ Endpoints API REST Local / Producción ]
  ├── GET /api/regiones
  ├── GET /api/provincias/{idRegion}
  ├── GET /api/distritos/{idProvince}
  └── GET /api/colegios/{ubigeo}
          │
          ▼
[ Clientes / Aplicaciones Externas ] (ej. admisionweb)
```

---

## 💻 Requisitos del Sistema

- **PHP:** `8.3.x` o superior, con las extensiones `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `tokenizer`, `xml` y `ctype`.
- **Laravel Framework:** `13.x`
- **Gestor de Paquetes:** Composer 2.x
- **Base de Datos:** MySQL 8.0+ o MariaDB 10.6+
- **Servidor Web Local:** Laragon, XAMPP o `php artisan serve`.
- **Document root:** la carpeta `public/` (nunca la raíz del proyecto).

---

## 🚀 Instalación y Puesta en Marcha

### 1. Clonar el repositorio
```bash
git clone https://github.com/hubelsolis/brigesnote.git
cd brigesnote
```

### 2. Instalar dependencias de Composer
```bash
composer install
```

### 3. Configurar variables de entorno
Copia el archivo de ejemplo y genera la clave de cifrado:
```bash
cp .env.example .env
php artisan key:generate
```

### 4. Crear la Base de Datos
Crea una base de datos vacía en tu gestor MySQL (ej. HeidiSQL o phpMyAdmin):
- **Nombre:** `escalebridge`
- **Cotejamiento:** `utf8mb4_unicode_ci`

### 5. Ejecutar las Migraciones
Crea las tablas en la base de datos:
```bash
php artisan migrate
```

### 6. Sincronizar el catálogo escolar
Descarga la información oficial desde MINEDU:
```bash
php artisan sync:locations
```

### 7. Iniciar el servidor
```bash
php artisan serve
```
La aplicación quedará disponible en `http://127.0.0.1:8000`.

---

## ⚙️ Configuración de Entorno (.env)

Asegúrate de tener estos valores configurados en tu archivo `.env`:

```env
APP_NAME='PROYECTO BASE'
LOGO_PROJECT='https://cdn-icons-png.flaticon.com/512/6886/6886480.png'
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

# Conexión MySQL
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=escalebridge
DB_USERNAME=root
DB_PASSWORD=

# Controlador de sesión en archivo (no requiere tabla 'sessions' en BD)
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
BROADCAST_CONNECTION=log
MAIL_MAILER=log
```

> ℹ️ Desde Laravel 11 cambiaron algunos nombres: `CACHE_DRIVER` → `CACHE_STORE`, `BROADCAST_DRIVER` → `BROADCAST_CONNECTION` y `MAIL_ENCRYPTION` → `MAIL_SCHEME`. Las variables `PUSHER_*` y `MIX_*` ya no se usan.

---

## 📥 Sincronización de Datos (Consola Artisan)

El comando central del sistema es:

```bash
php artisan sync:locations
```

### ¿Cómo opera la sincronización?
1. **Regiones (`/padron/rest/regiones`):** Descarga los 25 departamentos del país con sus nombres, coordenadas (`pointX`, `pointY`) y nivel de `zoom`.
2. **Provincias (`/padron/rest/provincias?idRegion={id}`):** Por cada región, descarga sus provincias.
3. **Distritos (`/padron/rest/distritos?idProvincia={id}`):** Por cada provincia, descarga los distritos correspondientes.
4. **Colegios (`/padron/rest/instituciones?estados=1&ubigeo={idDistrito}&start={n}&max=500`):** Por cada distrito (con barra de progreso), recorre **todas las páginas** de instituciones activas de 500 en 500 y las guarda por lotes (`upsert`) en la tabla `schools`. Cada colegio se identifica por **`codMod` + `anexo`**: un mismo código modular puede tener varias sedes, incluso en distritos distintos.

Todas las peticiones usan *timeout* (60 s), 3 reintentos y una pausa de 250 ms entre llamadas. Si un distrito falla, se registra el error y se continúa con el siguiente; al final se muestra un resumen con distritos procesados, colegios guardados y errores.

**Colegios dados de baja:** cada colegio recibido queda con `activo = true` y `ultima_sincronizacion` = inicio de la ejecución. Al terminar una sincronización **completa** (sin `--distrito`) y **sin ningún error**, los colegios que no aparecieron se marcan como `activo = false`. Si hubo errores, si ESCALE no devolvió distritos o si el comando se interrumpe, no se desactiva nada. Un colegio dado de baja que vuelve a aparecer se reactiva automáticamente.

Para sincronizar solo los colegios de algunos distritos (sin tocar regiones, provincias ni distritos; nunca desactiva colegios):
```bash
php artisan sync:locations --distrito=150101 --distrito=150132
```

> ⏱️ **Nota:** La sincronización total recorre más de 1,800 distritos; como referencia, solo Cercado de Lima (549), San Juan de Lurigancho (1,803) y Arequipa (537) tardan alrededor de 70 segundos.

---

## 📡 Documentación de la API REST

Todos los endpoints retornan respuestas en formato JSON bajo el prefijo `/api/`.

### 1. Listar Regiones / Departamentos
Obtiene todas las regiones del país.

- **URL:** `/api/regiones`
- **Método:** `GET`
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "idRegion": "15",
      "nombreRegion": "LIMA",
      "pointX": "-12.0463740",
      "pointY": "-77.0427930",
      "zoom": 10,
      "created_at": "2024-12-22T01:00:00.000000Z",
      "updated_at": "2024-12-22T01:00:00.000000Z"
    }
  ]
}
```

---

### 2. Listar Provincias por Región
Obtiene las provincias pertenecientes a una región (mediante los 2 primeros dígitos del código de departamento).

- **URL:** `/api/provincias/{idRegion}`
- **Método:** `GET`
- **Ejemplo:** `/api/provincias/15` (Provincias de Lima)
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "idProvincia": "1501",
      "nombreProvincia": "LIMA",
      "pointX": "-12.0463740",
      "pointY": "-77.0427930",
      "created_at": "2024-12-22T01:00:00.000000Z",
      "updated_at": "2024-12-22T01:00:00.000000Z"
    }
  ]
}
```

---

### 3. Listar Distritos por Provincia
Obtiene los distritos de una provincia (mediante los 4 dígitos de ubigeo provincial).

- **URL:** `/api/distritos/{idProvince}`
- **Método:** `GET`
- **Ejemplo:** `/api/distritos/1501` (Distritos de la provincia de Lima)
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "idDistrito": "150101",
      "nombreDistrito": "LIMA"
    },
    {
      "idDistrito": "150102",
      "nombreDistrito": "ANCON"
    }
  ]
}
```

---

### 4. Listar Colegios por Distrito (Ubigeo)
Obtiene las instituciones educativas **activas** de un distrito según su ubigeo de 6 dígitos (los colegios dados de baja no se devuelven), **paginadas** y ordenadas por `codMod` y `anexo`.

- **URL:** `/api/colegios/{ubigeo}?page={n}&per_page={m}`
- **Método:** `GET`
- **Parámetros opcionales:**
  - `page`: número de página, desde 1 (por defecto 1). Una página posterior a la última devuelve `data` vacío.
  - `per_page`: colegios por página, de 1 a 100 (por defecto 50).
  - Valores no válidos (por ejemplo `per_page=101` o `page=0`) responden **422** con el detalle del error.
- **Ejemplo:** `/api/colegios/150101?page=2&per_page=50`
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "codMod": "0273572",
      "anexo": "0",
      "idDistrito": "150101",
      "nombreCenEdu": "JUAN JIMENEZ PIMENTEL",
      "cenPob": "LIMA",
      "dirCen": "JR. LAMPA 240",
      "director": "PEDRO PEREZ",
      "dis_crecer": null,
      "dis_juntos": null,
      "activo": true,
      "ultima_sincronizacion": "2026-11-15T10:00:00.123456Z"
    }
  ],
  "meta": {
    "current_page": 2,
    "per_page": 50,
    "total": 549,
    "last_page": 11,
    "from": 51,
    "to": 100
  },
  "links": {
    "first": "/api/colegios/150101?per_page=50&page=1",
    "last": "/api/colegios/150101?per_page=50&page=11",
    "prev": "/api/colegios/150101?per_page=50&page=1",
    "next": "/api/colegios/150101?per_page=50&page=3"
  }
}
```

---

### Respuesta de error (404)
Cuando no hay resultados, los endpoints de provincias, distritos y colegios responden:
```json
{ "status": "error", "message": "No se encontraron distritos para esta provincia." }
```

> **Caché:** todas las respuestas (incluidos los 404) se guardan en el store de caché `api` (`storage/framework/cache/api`) durante un máximo de 24 h; la clave incluye los parámetros y, en colegios, `page` y `per_page`. Cada sincronización **sin errores** (completa o con `--distrito`) vacía ese store. Si cambias datos a mano en la base, límpialo con `php artisan cache:clear api`.

> Los decimales (`pointX`, `pointY`) se devuelven como **string** y `zoom` como **entero**. La API permite CORS desde cualquier origen y limita a **60 peticiones por minuto** por IP. Existe además `GET /up` como *health check*.

---

## 🧪 Tests

Los tests usan MySQL (las consultas usan `LEFT()`) sobre la base **`escalebridge_test`**, forzada en `phpunit.xml` para no tocar nunca la base real. Créala vacía una sola vez:

```sql
CREATE DATABASE escalebridge_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan test
```

---

## 📂 Estructura del Proyecto

```
brigesnote/
├── app/
│   ├── Console/Commands/
│   │   └── SyncLocations.php       # Comando 'sync:locations' (consumo de API ESCALE)
│   ├── Http/Controllers/
│   │   └── LocationController.php  # Controlador de endpoints /api/
│   └── Models/
│       ├── District.php            # Modelo Distritos
│       ├── Province.php            # Modelo Provincias
│       ├── Region.php              # Modelo Regiones
│       └── School.php              # Modelo Colegios / Escuelas
├── database/migrations/
│   ├── ..._create_regions_table.php
│   ├── ..._create_provinces_table.php
│   ├── ..._create_districts_table.php
│   └── ..._create_schools_table.php
├── bootstrap/app.php               # Rutas, middleware (throttle) y manejo de excepciones
├── public/                         # Document root (index.php, .htaccess, web.config)
├── routes/
│   └── api.php                     # Definición de rutas REST
├── tests/Feature/                  # Tests de la API y del comando de sincronización
├── .env.example                    # Plantilla de variables de entorno
└── .gitignore                      # Reglas de exclusión para Git
```

---

## 🛠️ Solución de Problemas Frecuentes

### 1. Error: `Base table or view not found: 1146 Table '...sessions' doesn't exist`
- **Causa:** En el archivo `.env` está configurado `SESSION_DRIVER=database` sin haber migrado la tabla de sesiones.
- **Solución:** Cambia en `.env` a `SESSION_DRIVER=file`.

### 2. Se ve el listado de archivos o el `.env` desde el navegador
- **Causa:** El servidor web apunta a la raíz del proyecto.
- **Solución:** Configura el *document root* en la carpeta `public/` (en Laragon: *Menu → Preferences → Document Root*, o un virtual host apuntando a `public`).

### 3. Error: `Your requirements could not be resolved` / `requires php >=8.4`
- **Causa:** Se ejecutó `composer update` sin respetar la plataforma.
- **Solución:** El `composer.json` fija `config.platform.php = 8.3.0`; no lo quites y usa `composer install` para respetar el `composer.lock`.

---

## 📄 Licencia

Este proyecto es de código abierto bajo la licencia [MIT](LICENSE).
