import 'bootstrap/dist/css/bootstrap.min.css';
import $ from 'jquery';
import * as bootstrap from 'bootstrap';

window.$ = window.jQuery = $;
window.bootstrap = bootstrap;

// Send the Laravel CSRF token with every jQuery AJAX request
$.ajaxSetup({
    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
});
