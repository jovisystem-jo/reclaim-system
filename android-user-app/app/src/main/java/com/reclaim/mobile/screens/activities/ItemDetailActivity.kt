package com.reclaim.mobile.screens.activities

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.ImageView
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.google.android.material.appbar.MaterialToolbar
import com.google.android.material.button.MaterialButton
import com.google.android.material.textfield.TextInputEditText
import com.reclaim.mobile.R
import com.reclaim.mobile.api.network.ApiErrorParser
import com.reclaim.mobile.api.network.MobileRepository
import com.reclaim.mobile.models.Item
import kotlinx.coroutines.launch

class ItemDetailActivity : AppCompatActivity() {

    private val repository by lazy { MobileRepository(this) }
    private var itemId: Int = 0

    private lateinit var toolbar: MaterialToolbar
    private lateinit var titleView: TextView
    private lateinit var metaView: TextView
    private lateinit var descriptionView: TextView
    private lateinit var imageView: ImageView
    private lateinit var claimLayout: View
    private lateinit var claimDescription: TextInputEditText
    private lateinit var claimButton: MaterialButton
    private lateinit var claimProgress: View
    private lateinit var editButton: MaterialButton

    private val editItemLauncher = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        if (result.resultCode == RESULT_OK) {
            setResult(RESULT_OK)
            loadDetail()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_item_detail)

        toolbar = findViewById(R.id.toolbarItemDetail)
        titleView = findViewById(R.id.textItemDetailTitle)
        metaView = findViewById(R.id.textItemDetailMeta)
        descriptionView = findViewById(R.id.textItemDetailDescription)
        imageView = findViewById(R.id.imageItemDetail)
        claimLayout = findViewById(R.id.layoutClaimForm)
        claimDescription = findViewById(R.id.editClaimDescription)
        claimButton = findViewById(R.id.buttonSubmitClaim)
        claimProgress = findViewById(R.id.progressClaimSubmit)
        editButton = findViewById(R.id.buttonEditItem)

        setSupportActionBar(toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        supportActionBar?.title = getString(R.string.item_details)

        itemId = intent.getIntExtra(EXTRA_ITEM_ID, 0)
        if (itemId <= 0) {
            finish()
            return
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

        editButton.setOnClickListener {
            editItemLauncher.launch(
                Intent(this, ReportItemActivity::class.java)
                    .putExtra(ReportItemActivity.EXTRA_ITEM_ID, itemId)
            )
        }

        loadDetail()
    }

    private fun loadDetail() {
        lifecycleScope.launch {
            repository.itemDetail(itemId)
                .onSuccess { item ->
                    supportActionBar?.title = item.title
                    titleView.text = item.title
                    metaView.text = buildMeta(item)
                    descriptionView.text = item.description
                    ImageLoader.load(imageView, item.imageUrl)
                    claimLayout.visibility = if (item.canClaim == true) View.VISIBLE else View.GONE
                    editButton.visibility = if (item.reportedByCurrentUser == true) View.VISIBLE else View.GONE
                }
                .onFailure {
                    Toast.makeText(this@ItemDetailActivity, ApiErrorParser.message(it), Toast.LENGTH_LONG).show()
                    finish()
                }
        }
    }

    private fun buildMeta(item: Item): String {
        val lines = mutableListOf<String>()
        lines += "${item.status.uppercase()} | ${item.category}"
        lines += item.foundLocation.ifBlank { item.location }

        if (!item.reportedDate.isNullOrBlank()) {
            lines += "Reported: ${item.reportedDate}"
        }

        if (item.deliveryLocation.isNotBlank()) {
            lines += "Delivery: ${item.deliveryLocation}"
        }

        if ((item.claimCount ?: 0) > 0) {
            lines += "Claims: ${item.claimCount}"
        }

        return lines.joinToString("\n")
    }

    override fun onSupportNavigateUp(): Boolean {
        finish()
        return true
    }

    companion object {
        const val EXTRA_ITEM_ID = "item_id"
    }
}
