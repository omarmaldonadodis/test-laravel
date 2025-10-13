formatea el siguiente texto de tal manera que todo quede bien en markdown 
# Proyecto Laravel: WebHook de Integración Moodle & Medusa

## 📌 Introducción

Este proyecto implementa un **WebHook en Laravel** que forma parte de un ecosistema de servicios interconectados mediante **Docker**.  

Su objetivo es automatizar la gestión de usuarios y sincronizar datos entre **Moodle** y **Medusa**, garantizando consistencia en la información del sistema y reduciendo la intervención manual.

### Funciones principales:
- Crear, editar y listar usuarios en **Moodle** mediante un token con permisos específicos.  
- Sincronizar usuarios, pedidos y productos con **Medusa**.  
- Operar dentro de una **red Docker compartida**, asegurando comunicación fluida entre contenedores.

---

## 🛠 Requisitos previos

Antes de ejecutar este proyecto, asegúrate de tener:

1. **Docker** y **Docker Compose** instalados:  
   - [Docker](https://docs.docker.com/get-docker/)  
   - [Docker Compose](https://docs.docker.com/compose/install/)  

2. Contenedores Moodle y Medusa desplegados en la **misma red Docker**.  

3. Permisos de administrador en Moodle para crear un **Custom Service** y generar el token.  

---

## 🔑 Obtención de tokens y configuración de servicios

### 1. Moodle

Para que Laravel pueda interactuar con Moodle, necesitas un **Custom Service** y un **token de usuario administrador**:

1. Accede como administrador a Moodle:  
../admin/settings.php?section=externalservices
- [Documentación Moodle: Web Services](https://docs.moodle.org/311/en/Using_web_services)

2. Crea un **Custom Service** con los siguientes privilegios:  
core_course_get_courses
core_course_get_courses_by_field
core_enrol_get_users_courses
core_user_create_users
core_user_get_users_by_field
core_webservice_get_site_info

3. Genera un **token** asociado al servicio creado.  
- Asegúrate de que el correo del usuario coincida con el del administrador y que el token esté activo.  
- Este token se configura en `.env` como `MOODLE_TOKEN`.

4. Obtén también el **nombre del servicio** (`MOODLE_SERVICE`) y el **ID del curso por defecto** (`MOODLE_DEFAULT_COURSE_ID`) si necesitas asignar usuarios automáticamente a un curso.

---

### 2. Medusa

Laravel se integra con Medusa para sincronizar datos. Para esto:

1. Asegúrate de que el contenedor Medusa esté levantado y accesible desde Laravel en la **misma red Docker**.  
- Por ejemplo, si usas Docker Compose, el nombre del contenedor puede ser `medusa` y su URL interna `http://medusa:9000`.

2. Si la API de Medusa requiere autenticación, genera una **API Key** desde el panel de administración de Medusa.  
- Esta se configurará en `.env` como `MEDUSA_API_KEY`.  
- [Documentación Medusa Admin API](https://docs.medusajs.com/api/admin)

---

## 🌐 Configuración de la red Docker

1. **Verificar redes existentes:**  
```bash
docker network ls
Conectar los contenedores a la misma red:
docker network connect <nombre_red> <contenedor_moodle>
docker network connect <nombre_red> <contenedor_medusa>
docker network connect <nombre_red> <contenedor_laravel>
Recomendación: usar Docker Compose para manejar redes y dependencias automáticamente.



⚙️ Variables críticas en .env
Estas son las variables que debes configurar para que el proyecto funcione correctamente:
Moodle
Variable	Descripción	Fuente / Cómo obtenerla
MOODLE_URL	URL del servicio web de Moodle accesible desde Laravel	Nombre del contenedor de Moodle dentro de la red Docker (ej. http://moodle-docker-webserver-1)
MOODLE_TOKEN	Token de acceso al Web Service de Moodle	Generado en Moodle al crear un Custom Service con permisos
MOODLE_SERVICE	Nombre del servicio personalizado de Moodle	Nombre asignado al Custom Service creado (ej. laravel2)
MOODLE_DEFAULT_COURSE_ID	ID del curso por defecto para asignar usuarios	Revisar en Moodle en Cursos > Gestionar cursos
Medusa
Variable	Descripción	Fuente / Cómo obtenerla
MEDUSA_URL	URL del contenedor Medusa accesible desde Laravel	Nombre del contenedor Medusa dentro de la misma red Docker (ej. http://medusa:9000)
MEDUSA_API_KEY	Clave de API para autenticación	Generada en Medusa si la API requiere autenticación. Medusa Admin API
WebHook de Laravel
Variable	Descripción	Fuente / Cómo obtenerla
WEBHOOK_SECRET	Clave secreta para validar los WebHooks entrantes	Definir manualmente para proteger la comunicación desde Moodle hacia Laravel
LARAVEL_WEBHOOK_SECRET	Clave interna de seguridad para Laravel	Definir manualmente; debe coincidir con la usada en los WebHooks configurados

Importante:
Los nombres de contenedor (MOODLE_URL y MEDUSA_URL) deben ser correctos dentro de la misma red Docker.
Genera los tokens en Moodle y Medusa antes de iniciar Laravel.
Copia .env.example a .env y completa únicamente estas variables críticas antes de levantar los contenedores.

🚀 Ejecución del proyecto
Accede al directorio del proyecto:
cd tu-proyecto
Construye y levanta los contenedores:
docker-compose up --build -d
Verifica que los contenedores estén activos y conectados correctamente:
docker ps
docker network inspect <nombre_red>
Para pruebas internas o ejecución de scripts manuales:
docker exec -it <nombre_del_contenedor> bash


📑 Uso
Una vez desplegado, el WebHook gestiona automáticamente:
Creación y actualización de usuarios en Moodle.
Sincronización de datos en Medusa: usuarios, pedidos y productos.
Comunicación segura dentro de la red Docker, sin intervención manual.
