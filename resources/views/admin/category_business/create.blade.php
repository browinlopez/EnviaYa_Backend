@extends('adminlte::page')

@section('title','Crear Categoría')

@section('content_header')
<h1>Crear Categoría de Negocio</h1>
@stop

@section('content')
<form action="{{ route('admin.category-business.store') }}" method="POST">
    @csrf
    @include('admin.category_business.partials.forms')
    <button type="submit" class="btn btn-primary mt-3"><i class="fas fa-save"></i> Guardar</button>
</form>
@stop
