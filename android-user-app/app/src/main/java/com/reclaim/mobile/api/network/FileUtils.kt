package com.reclaim.mobile.api.network

import android.content.Context
import android.net.Uri
import java.io.File

object FileUtils {
    fun copyUriToCache(context: Context, uri: Uri): File {
        val fileName = "upload_${System.currentTimeMillis()}"
        val target = File(context.cacheDir, fileName)
        context.contentResolver.openInputStream(uri).use { input ->
            target.outputStream().use { output ->
                input?.copyTo(output)
            }
        }
        return target
    }
}
