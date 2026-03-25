// FAQ accordion
document.querySelectorAll('.faq-question').forEach(function(q) {
    q.addEventListener('click', function() {
        var item = q.closest('.faq-item');
        var wasOpen = item.classList.contains('open');
        document.querySelectorAll('.faq-item.open').forEach(function(i) { i.classList.remove('open'); });
        if (!wasOpen) item.classList.add('open');
    });
});

// Ingredient checkboxes
document.querySelectorAll('.ingredients-list li').forEach(function(li) {
    li.addEventListener('click', function() { li.classList.toggle('checked'); });
});
