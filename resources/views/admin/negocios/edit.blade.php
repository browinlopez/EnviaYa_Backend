@extends('adminlte::page')

@section('title','Editar Negocio')

@section('content_header')
<h1>Editar Negocio</h1>
@stop

@section('content')
<form action="{{ route('admin.negocios.update',$business->busines_id) }}" method="POST" enctype="multipart/form-data">
    @csrf
    @method('PUT')
  @include('admin.negocios.partials.forms')
    <button type="submit" class="btn btn-primary mt-3"><i class="fas fa-save"></i> Actualizar</button>
</form>
@stop
