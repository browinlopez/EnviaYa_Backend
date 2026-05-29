# EnviaYa Frontend Integration Contract (React Native)

Bienvenido a la documentación oficial del Backend de EnviaYa. Toda esta API ha sido expuesta automáticamente a través de OpenAPI para proveer contratos exactos y siempre actualizados.

## Estructura Base y Autenticación
La mayoría de los endpoints están protegidos por **Sanctum**.
Debes inyectar en todas tus llamadas autenticadas el header:
```json
{
  "Authorization": "Bearer {token}",
  "Accept": "application/json"
}
```

## Manejo de Errores Estándar
El backend arrojará códigos HTTP predecibles.
- `200/201`: Éxito
- `401`: Token inválido o expirado. (Redirigir a Login).
- `403`: Permisos insuficientes para tu Rol.
- `404`: Recurso no encontrado.
- `422`: Error de validación de formulario. (Revisa el objeto `errors` en la respuesta).
- `500`: Error del servidor.

## Tipos de Datos Globales
- **Fechas**: Todas las fechas retornadas están en formato ISO 8601 (`YYYY-MM-DD HH:mm:ss` o similar).
- **Distancias y Tarifas**: Calculadas internamente vía PostGIS. Las respuestas incluirán valores monetarios decimales y distancias métricas enteras.
- **Roles y Permisos**: Identificados en el payload de `/api/user`.

---

# Eventos Realtime y WebSockets (Laravel Reverb)

Para la integración con Websockets, se utiliza `pusher-js` en el cliente.

### 1. Rastreo GPS de Repartidor
**Canal (Privado):** `gps.order.{orderId}`
**Evento:** `LocationUpdated`
**Payload:**
```json
{
  "latitude": 4.123456,
  "longitude": -74.123456,
  "distance_meters": 1200
}
```
**Cuándo Ocurre:** Cuando el conductor transmite su ubicación y el backend graba el punto asíncronamente en PostGIS.
**Quién lo Recibe:** Usuario comprador / Tienda.

### 2. Chat Avanzado
**Canal (Privado):** `chat.{chatId}`
**Evento:** `MessageSent`
**Payload:**
```json
{
  "message_id": 105,
  "chat_id": 12,
  "user_id": 4,
  "name": "Juan Perez",
  "role_id": 2,
  "content": "Ya voy en camino",
  "file_url": "https://s3.../image.jpg",
  "file_type": "image/jpeg",
  "created_at": "2026-05-28T21:00:00.000000Z"
}
```
**Cuándo Ocurre:** Al enviar un mensaje mediante `/api/chat/send`.
**Client Events:** Usar Whisper (`typing`) para indicadores de escritura.
**Lecturas:** Consumir `/api/chat/mark-as-read` actualizará los punteros.

### 3. Notificaciones Personales
**Canal (Privado):** `App.Models.User.{userId}`
**Evento:** `Illuminate\Notifications\Events\BroadcastNotificationCreated` (El `type` interno será `App\Notifications\OrderStatusUpdated`)
**Payload:**
```json
{
  "order_id": 1024,
  "state": 4,
  "message": "Tu pedido fue entregado",
  "time": "2026-05-28 21:30:00"
}
```
**Cuándo Ocurre:** Cuando cambia el estado del pedido u otras alertas globales.
**Quién lo Recibe:** El usuario suscrito (Comprador/Tienda/Domiciliario).
