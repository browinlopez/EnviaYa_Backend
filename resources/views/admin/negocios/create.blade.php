@extends('adminlte::page')

@section('title','Crear Negocio')

@section('content_header')
<h1>Crear Negocio</h1>
@stop

@section('content')
<form action="{{ route('admin.negocios.store') }}" method="POST" enctype="multipart/form-data">
    @csrf
   @include('admin.negocios.partials.forms')
    <button type="submit" class="btn btn-success mt-3"><i class="fas fa-save"></i> Guardar</button>
</form>
@stop
