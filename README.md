# 🚌 ETTUR La Universidad — Sistema Integral de Recaudación

## Arquitectura de Despliegue

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   FRONTEND      │     │   BACKEND       │     │   BASE DATOS    │
│   (Vercel)      │────▶│   (Railway)     │────▶│   (Railway)     │
│   HTML/CSS/JS   │     │   PHP/Apache    │     │   MySQL         │
│                 │     │                 │     │                 │
│ Repo GitHub #2  │     │ Repo GitHub #1  │     │ MySQL Workbench │
└─────────────────┘     └─────────────────┘     └─────────────────┘
```

---

## 📋 PASO 1: Base de Datos (Railway + MySQL Workbench)

### 1.1 Crear MySQL en Railway
1. Ir a [railway.app](https://railway.app) → New Project → Add MySQL
2. Esperar a que se provisione
3. En la pestaña **Variables**, copiar:
   - `MYSQLHOST` → será tu `DB_HOST`
   - `MYSQLPORT` → será tu `DB_PORT`
   - `MYSQLDATABASE` → será tu `DB_NAME`
   - `MYSQLUSER` → será tu `DB_USER`
   - `MYSQLPASSWORD` → será tu `DB_PASS`

### 1.2 Conectar MySQL Workbench
1. Abrir MySQL Workbench → New Connection
2. Hostname: el `MYSQLHOST` de Railway
3. Port: el `MYSQLPORT`
4. Username: el `MYSQLUSER`
5. Password: el `MYSQLPASSWORD`
6. Test Connection → OK

### 1.3 Ejecutar Schema
1. En MySQL Workbench, abrir el archivo `backend/database/schema.sql`
2. Ejecutar todo el script (⚡ botón rayo)
3. Verificar que se crearon las tablas: roles, usuarios, tarifas, trabajador_config, periodos_pago, pagos, auditoria, sesiones

---

## 📋 PASO 2: Backend (GitHub + Railway)

### 2.1 Crear Repositorio GitHub #1
```bash
# En tu computadora
mkdir ettur-backend
cd ettur-backend

# Copiar TODOS los archivos de la carpeta 'backend/' aquí
# Estructura debe ser:
# ettur-backend/
# ├── .env.example
# ├── .gitignore
# ├── .htaccess
# ├── Dockerfile
# ├── index.php
# ├── config/
# │   ├── database.php
# │   └── helpers.php
# ├── middleware/
# │   └── Auth.php
# ├── api/
# │   ├── auth/index.php
# │   ├── usuarios/index.php
# │   ├── tarifas/index.php
# │   ├── pagos/index.php
# │   └── reportes/index.php
# ├── database/
# │   └── schema.sql
# └── uploads/
#     └── .gitkeep

git init
git add .
git commit -m "Backend ETTUR v1.0"
git branch -M main
git remote add origin https://github.com/TU_USUARIO/ettur-backend.git
git push -u origin main
```

### 2.2 Deploy en Railway
1. En Railway → mismo proyecto donde creaste MySQL
2. Click **+ New** → **GitHub Repo** → seleccionar `ettur-backend`
3. Railway detectará el Dockerfile automáticamente
4. En **Variables** del servicio backend, agregar:
   ```
   DB_HOST=tu_mysql_host_railway
   DB_PORT=tu_puerto
   DB_NAME=railway (o el nombre de tu BD)
   DB_USER=root
   DB_PASS=tu_password_mysql
   JWT_SECRET=una_clave_secreta_larga_y_segura
   CORS_ORIGIN=https://tu-frontend.vercel.app
   APP_ENV=production
   APP_DEBUG=false
   ```
5. En **Settings** → generar dominio público
6. Copiar la URL del backend (ej: `https://ettur-backend-production.up.railway.app`)

---

## 📋 PASO 3: Frontend (GitHub + Vercel)

### 3.1 Crear Repositorio GitHub #2
```bash
mkdir ettur-frontend
cd ettur-frontend

# Copiar TODOS los archivos de la carpeta 'frontend/' aquí
# Estructura:
# ettur-frontend/
# ├── index.html
# ├── vercel.json
# ├── .gitignore
# ├── css/
# │   └── app.css
# └── js/
#     ├── config.js    ← EDITAR API_BASE AQUÍ
#     ├── api.js
#     ├── auth.js
#     ├── ui.js
#     ├── app.js
#     └── pages/
#         ├── dashboard.js
#         ├── pagos.js
#         ├── validar.js
#         ├── reportes.js
#         ├── usuarios.js
#         ├── tarifas.js
#         └── perfil.js

git init
git add .
git commit -m "Frontend ETTUR v1.0"
git branch -M main
git remote add origin https://github.com/TU_USUARIO/ettur-frontend.git
git push -u origin main
```

### 3.2 IMPORTANTE: Configurar URL del Backend
Editar `js/config.js` línea 6:
```javascript
API_BASE: localStorage.getItem('ettur_api_url') || 'https://TU-BACKEND.railway.app',
```
Reemplazar con la URL real de tu backend en Railway.

### 3.3 Deploy en Vercel
1. Ir a [vercel.com](https://vercel.com) → Add New Project
2. Importar `ettur-frontend` desde GitHub
3. Framework Preset: **Other**
4. Build Command: (dejar vacío)
5. Output Directory: `.` (punto)
6. Deploy

### 3.4 Actualizar CORS en Backend
Una vez que Vercel te dé la URL del frontend, volver a Railway y actualizar:
```
CORS_ORIGIN=https://tu-frontend.vercel.app
```

---

## 🔐 Credenciales Iniciales

| Rol | Usuario | Contraseña |
|-----|---------|------------|
| Admin | `admin` | `Admin2025!` |

⚠️ **Cambiar la contraseña inmediatamente después del primer login.**

---

## 📱 Roles y Funciones

### Trabajador
- Ver su deuda total y periodos pendientes
- Subir comprobante de pago (Yape/Transferencia)
- Ver historial de sus pagos y estados

### Coadministrador
- Validar pagos pendientes (aprobar/rechazar)
- Ver reportes de recaudación
- Ver liquidación por trabajador

### Administrador General
- Todo lo del Coadmin +
- Gestionar usuarios (crear, editar, dar de baja)
- Configurar tarifas (Verano/Normal)
- Establecer fecha de inicio de cobro por trabajador
- Ver auditoría del sistema

---

## ⚙️ Reglas de Negocio

1. **Tarifas**: Verano (1 Ene - 15 Abr) / Normal (16 Abr - 31 Dic). Configurable.
2. **Puesta en Marcha**: Cada trabajador tiene una fecha de inicio. Solo se cobran periodos desde esa fecha.
3. **Validación Correlativa**: Un trabajador NO puede pagar un periodo si tiene anteriores sin pagar.
4. **Periodos**: Quincenales (1-15 y 16-fin de mes).

---

## 🔧 Notas Técnicas

- **Stack**: PHP 8.2 + PDO + MySQL + Bootstrap 5 + Vanilla JS
- **Seguridad**: JWT tokens, prepared statements (PDO), sanitización de inputs
- **Mobile First**: Diseño optimizado para móviles, compatible con Apache Cordova
- **Comprobantes**: Se almacenan en `uploads/YYYY/MM/` (máx 5MB, JPG/PNG/WebP)
