package com.reclaim.mobile.screens.activities

import android.os.Bundle
import android.view.View
import android.widget.ImageView
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.textfield.TextInputEditText
import com.reclaim.mobile.R
import com.reclaim.mobile.api.network.ApiErrorParser
import com.reclaim.mobile.api.network.MobileRepository
import kotlinx.coroutines.launch

class ItemDetailActivity : AppCompatActivity() {

    private val repository by lazy { MobileRepository(this) }
    private var itemId: Int = 0

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_item_detail)

        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        supportActionBar?.title = "Item Details"

        itemId = intent.getIntExtra(EXTRA_ITEM_ID, 0)
        if (itemId <= 0) {
            finish()
            return
        }

        val title: TextView = findViewById(R.id.textItemDetailTitle)
        val meta: TextView = findViewById(R.id.textItemDetailMeta)
        val description: TextView = findViewById(R.id.textItemDetailDescription)
        val image: ImageView = findViewById(R.id.imageItemDetail)
        val claimLayout: View = findViewById(R.id.layoutClaimForm)
        val claimDescription: TextInputEditText = findViewById(R.id.editClaimDescription)
        val claimButton: MaterialButton = findViewById(R.id.buttonSubmitClaim)
        val claimProgress: View = findViewById(R.id.progressClaimSubmit)

        fun loadDetail() {
            lifecycleScope.launch {
                repository.itemDetail(itemId)
                    .onSuccess { item ->
                        title.text = item.title
                        meta.text = "${item.status.uppercase()} • ${item.category}\n${item.foundLocation.ifBlank { item.location }}"
                        description.text = item.description
                        ImageLoader.load(image, item.imageUrl)
                        claimLayout.visibility = if (item.canClaim == true) View.VISIBLE else View.GONE
                    }
                    .onFailure {
                        Toast.makeText(this@ItemDetailActivity, ApiErrorParser.message(it), Toast.LENGTH_LONG).show()
                        finish()
                    }
            }
        }

        claimButton.setOnClickListener {
            val text = claimDescription.text?.toString()?.trim().orEmpty()
            if (text.isBlank()) {
                claimDescription.error = "Please describe your claim."
                return@setOnClickListener
            }

            claimProgress.visibility = View.VISIBLE
            claimButton.isEnabled = false
            lifecycleScope.launch {
                repository.submitClaim(itemId, text)
                    .onSuccess {
                        Toast.makeText(this@ItemDetailActivity, "Claim submitted successfully.", Toast.LENGTH_LONG).show()
                        claimDescription.setText("")
                        loadDetail()
                    }
                    .onFailure {
                        Toast.makeText(this@ItemDetailActivity, ApiErrorParser.message(it), Toast.LENGTH_LONG).show()
                    }

                claimProgress.visibility = View.GONE
                claimButton.isEnabled = true
            }
        }

        loadDetail()
    }

    override fun onSupportNavigateUp(): Boolean {
        finish()
        return true
    }

    companion object {
        const val EXTRA_ITEM_ID = "item_id"
    }
}
