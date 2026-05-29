# Guía de Cliente: Chat en Tiempo Real para React Native

Esta guía documenta la integración de mensajería asíncrona, eventos de 'typing', confirmaciones de lectura, y carga de archivos usando Laravel Echo / Reverb en EnviaYa.

## 1. Conexión al Canal del Chat (Private Channel)

Cada chat (1-to-1 o grupal) emite eventos sobre un canal privado protegido.

```javascript
// Asegúrate de que el user ya está autenticado con Bearer Token en echo
const chatId = 15; // Obtenido del endpoint GET /api/chats

const chatChannel = echo.private(`chat.${chatId}`);

chatChannel.listen('MessageSent', (e) => {
    console.log("Nuevo Mensaje Recibido:", e);
    // e.message_id, e.content, e.file_url, e.file_type, e.created_at
});
```

## 2. Eventos de Presencia y Typing (Whisper)

Para no saturar la base de datos con pulsaciones de teclas, se utilizan *Client Events* (Whisper).

**Emitir que el usuario está escribiendo:**
```javascript
// Cuando el TextInput de React Native cambie
chatChannel.whisper('typing', {
    user_id: miUserId,
    name: miNombre
});
```

**Escuchar cuando otro usuario escribe:**
```javascript
chatChannel.listenForWhisper('typing', (e) => {
    console.log(`${e.name} está escribiendo...`);
    // Mostrar indicador por 3 segundos
});
```

## 3. Confirmaciones de Lectura (Read Receipts)

Cuando el usuario entra a la pantalla del chat o escrollea hasta abajo, notifica al servidor:

```javascript
axios.post('http://tu-dominio.com/api/chat/mark-as-read', {
    chat_id: chatId,
    user_id: miUserId
});
```

En futuras implementaciones, este endpoint puede emitir un evento `MessagesRead` por Websockets para que la otra parte marque el doble check azul en tiempo real.

## 4. Archivos Adjuntos

Para enviar archivos, utiliza FormData enviando a `/api/chat/send` (o sube primero a S3 y envía la URL).
El backend soporta `file_url` y `file_type`. El objeto `MessageSent` emitido contendrá estos campos.
