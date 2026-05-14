package com.reclaim.mobile.screens.activities

import android.graphics.BitmapFactory
import android.widget.ImageView
import java.net.URL

object ImageLoader {
    fun load(imageView: ImageView, url: String?) {
        if (url.isNullOrBlank()) {
            imageView.setImageDrawable(null)
            return
        }

        Thread {
            runCatching { URL(url).openStream().use(BitmapFactory::decodeStream) }
                .onSuccess { bitmap ->
                    imageView.post {
                        imageView.setImageBitmap(bitmap)
                    }
                }
        }.start()
    }
}
