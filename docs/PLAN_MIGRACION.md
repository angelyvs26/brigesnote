# Plan de migración a PHP 8.3

**Proyecto:** brigesnote, API REST de ubigeo y colegios del Perú (datos de MINEDU / ESCALE)
**Objetivo:** migrar de Laravel 7 (PHP 7.4/8.0) a PHP 8.3
**Fecha de ejecución:** 15/11/2026 · **Plazo:** lunes 16/11/2026
**Estado:** ✅ completado y publicado en `main` (CI en verde)

> El plan de trabajo se presentó y aprobó el 15/11/2026, **antes de modificar código**, a partir de un análisis completo del repositorio. Este documento lo registra junto con las decisiones tomadas, lo que realmente se ejecutó y sus resultados.

---

## 1. Punto de partida

Antes de empezar, el repositorio estaba limpio y con un commit de inicio: `e947779` "iniciando migracion a 8.3" (12/11/2026). Todo el trabajo se hizo en ramas aparte y se integró a `main` al final.

### Diagnóstico inicial

| Componente | Versión | Situación con PHP 8.3 |
|---|---|---|
| laravel/framework | 7.30.7 | ❌ No funciona con PHP ≥ 8.1 (error `ArrayAccess`) |
| fideloper/proxy | 4.4.2 | ❌ Abandonado; integrado en Laravel 9+ |
| fruitcake/laravel-cors | 2.2.0 | ❌ Abandonado; integrado en Laravel 9+ |
| facade/ignition | 2.17.7 | ❌ Reemplazado por spatie/laravel-ignition |
| nunomaduro/collision · phpunit | 4.3 · 9.6 | ⚠️ Requieren versiones mayores |
| laravel-mix / webpack | 5.x | Sin uso (es una API pura) |

### Riesgos detectados

1. **Sin carpeta `public/`:** `index.php` estaba en la raíz, así que el `.env` y otros archivos sensibles quedaban expuestos por HTTP.
2. **Contrato JSON:** los tipos de las respuestas debían conservarse entre versiones.
3. **Código muerto:** un modelo duplicado, la ruta `/api/user` sin guard funcional y configuración en lugares incorrectos.
4. **Migraciones en producción:** las migraciones por defecto del esqueleto nuevo podían chocar con las ya ejecutadas.
5. **Fragilidad en PHP 8:** el comando de sincronización fallaba ante respuestas nulas de ESCALE.
6. **Sin tests:** solo existían los de ejemplo.

---

## 2. Estrategia

**Esqueleto nuevo y portar el código, en lugar de subir versión por versión (7 → 8 → … → 13).** El código propio eran unos 10 archivos, así que partir del esqueleto oficial era más rápido y dejaba una base limpia.

### Decisiones aprobadas

| Pregunta | Decisión |
|---|---|
| Versión objetivo | **Laravel 13** (exige PHP ≥ 8.3) |
| Despliegue | Carpeta **`public/`** como *document root* |
| Contrato JSON | Conservar los tipos originales (decimales como string, `zoom` como entero) |
| Alcance | Primero la migración; las mejoras después, por partes |
| Entorno de verificación | Laragon (PHP 8.3, MySQL 8, Composer), porque Docker no tenía espacio en disco |

---

## 3. Fases planificadas y su ejecución

| Fase | Plan | Ejecución |
|---|---|---|
| 0. Preparación | Rama de trabajo, entorno PHP 8.3, respaldo de la base | ✅ Rama `feature/migracion-php83` y entorno Laragon con PHP 8.3.36 |
| 1. Línea base | Capturar las respuestas de la versión en Laravel 7 y escribir tests de caracterización | ⚠️ Tests de caracterización escritos. La captura en Laravel 7 **no se hizo**: Docker falló por falta de espacio en disco. El contrato quedó fijado en los tests a partir del análisis del código |
| 2. Esqueleto nuevo | Laravel 13, `composer.json` con PHP 8.3 y sin paquetes obsoletos | ✅ `composer.lock` resuelto con `platform.php = 8.3.0` |
| 3. Portar el código | Modelos, controlador, comando, CORS, proxies y migraciones | ✅ Mismos nombres de archivo de migración, para que no se vuelvan a ejecutar |
| 4. Despliegue | `public/` como *document root* | ✅ `.env` y `composer.json` ya no son accesibles por HTTP (responden 404) |
| 5. Validación | Tests, contrato JSON y sincronización | ✅ Tests en verde y endpoints probados por HTTP. Además se detectó y corrigió un error grave: con un `.env` de Laravel 7, la API respondía 500 |
| 6. Documentación | README y `.env.example` | ✅ |

---

## 4. Commits

| Commit | Contenido |
|---|---|
| `f8ccd4d` | Migración a PHP 8.3 y Laravel 13 |
| `d484cef` | Compatibilidad con `.env` de Laravel 7 (`CACHE_DRIVER`) |
| `9a8eee0` | Parte 1: sincronización completa (paginación de ESCALE, clave `codMod`+`anexo`, `upsert`, reintentos) |
| `46835c8` | Parte 2: validación de parámetros e índices en las consultas |
| `aaec092` | Parte 3: colegios dados de baja |
| `e91429a` | Parte 4: paginación de `/api/colegios` y caché |
| `010b328` | Parte 5: programación mensual, CORS configurable, limpieza y CI |
| `37f56aa` · `c7ca29d` | Merges `--no-ff` a `main` (migración y mejoras) |
| `f83ad37` | Repositorio oficial en README y `composer.json` |

---

## 5. Resultado

- **Versiones:** PHP 8.3 y Laravel 13.42.
- **Tests:** 81 tests (1,198 aserciones) y Pint sin observaciones.
- **CI:** GitHub Actions en verde con PHP 8.3 y MySQL 8 (ejecuciones #1 y #2).
- **Prueba con datos reales:** `migrate:fresh` y sincronización contra ESCALE con 26,245 colegios guardados; los endpoints responden correctamente.
- **Despliegue simulado:** `composer install --no-dev`, `APP_DEBUG=false` y `php artisan optimize` sin errores.

### Limitaciones conocidas

- **No hubo comparación directa contra Laravel 7** (fase 1, ver arriba).
- **La sincronización completa no se ejecutó de principio a fin:** se detuvo al 28%. Midiendo, 524 distritos tomaron unos 92 minutos, más 16 minutos de ubigeo; el total estimado es de varias horas.

### Pendientes

1. Rendimiento de la sincronización: reducir el timeout, agregar *backoff* y reintentar al final los distritos fallidos.
2. Limpiar la caché de la API después de `migrate` / `migrate:fresh`.
3. Unificar el formato de las respuestas 404.
4. Proteger contra distritos vacíos por fallos de ESCALE (por ejemplo, un umbral máximo de bajas).
5. Documentar la API con OpenAPI y versionarla (`/api/v1`).

El detalle de instalación, sincronización y despliegue está en el [README](../README.md).
