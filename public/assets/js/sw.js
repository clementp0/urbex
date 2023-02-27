const CACHE_NAME = 'a2urbex-v1';
const urlsToCache = [
  '/',
];

self.addEventListener('install', function(event) {
  // Perform install steps
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(function(cache) {
        console.log('Opened cache');
        return cache.addAll(urlsToCache);
      })
  );
});

self.addEventListener('fetch', function(event) {
    // Only cache GET requests with http/https scheme
    if (event.request.method === 'GET' && /^(http|https):\/\/.+$/i.test(event.request.url)) {
      event.respondWith(
        caches.match(event.request)
          .then(function(response) {
            // Cache hit - return response
            if (response) {
              return response;
            }
            // Clone the request to avoid changing the original
            const fetchRequest = event.request.clone();
  
            return fetch(fetchRequest).then(
              function(response) {
                // Check if we received a valid response
                if(!response || response.status !== 200 || response.type !== 'basic') {
                  return response;
                }
                // Clone the response to avoid changing the original
                const responseToCache = response.clone();
  
                caches.open(CACHE_NAME)
                  .then(function(cache) {
                    cache.put(event.request, responseToCache);
                  });
                return response;
              }
            );
          })
        );
    }
  });

  self.addEventListener('message', function(event) {
    if (event.data && event.data.type === 'notification') {
      var notificationData = event.data.notificationData;
      var options = {
        body: notificationData.message,
        icon: '../a2urbex192x192eee.png'
      };
      event.waitUntil(self.registration.showNotification(notificationData.title, options));
    }
  });
  
