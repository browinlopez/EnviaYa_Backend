@extends('adminlte::page')

@section('title','Editar Categoría')

@section('content_header')
<h1>Editar Categoría de Negocio</h1>
@stop

@section('content')
<form action="{{ route('admin.category-business.update', $category->id) }}" method="POST" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    @include('admin.category_business.partials.forms')
</form>
@stop
