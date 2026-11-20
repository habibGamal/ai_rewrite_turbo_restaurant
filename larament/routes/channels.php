<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Shift specific channels
Broadcast::channel('shift.{shiftId}', function ($user, $shiftId) {
    return $user !== null; // Any authenticated user can listen to shift updates
});
