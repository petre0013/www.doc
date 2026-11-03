<?php

test('the application redirects guests to login', function () {
    $this->get('/')->assertRedirect();
});
