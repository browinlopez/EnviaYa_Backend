@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<form method="POST" action="{{ route('resend.verification.email') }}">
    @csrf
    <input type="hidden" name="email" value="{{ session('email_for_verification') }}">
    <button type="submit" class="btn btn-primary">Reenviar correo de verificación</button>
</form>

<a href="{{ route('login') }}" class="btn btn-link">Volver al login</a>