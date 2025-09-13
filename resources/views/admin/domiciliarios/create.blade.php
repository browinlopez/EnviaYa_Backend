@extends('adminlte::page')

@section('title', isset($domiciliary) ? 'Editar Domiciliario' : 'Crear Domiciliario')

@section('content_header')
<h1>{{ isset($domiciliary) ? 'Editar Domiciliario' : 'Crear Domiciliario' }}</h1>
@stop

@section('content')
<form action="{{ isset($domiciliary) ? route('admin.domiciliarios.update', $domiciliary->domiciliary_id) : route('admin.domiciliarios.store') }}" method="POST">
    @csrf
    @if(isset($domiciliary)) 
        @method('PUT') 
    @endif
    @include('admin.domiciliarios.partials.form')
</form>
@stop
