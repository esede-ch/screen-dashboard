'use strict';
// Visual prototype only. No form submission, credential storage or network call.
document.getElementById('connect').addEventListener('click', () => {
  document.getElementById('password').value = '';
  document.getElementById('demo-message').hidden = false;
});
